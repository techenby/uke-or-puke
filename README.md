# Uke or Puke

A Guitar Hero-style ukulele rhythm game for iOS and Android, built with Laravel and NativePHP Mobile.

The name comes from [*Uke or Puke*](https://scrooge-mcduck.fandom.com/wiki/Uke_or_Puke), the Japanese-import arcade cabinet in the DuckTales 2017 episode "Daytrip of Doom" — a rhythm game with a ukulele-shaped controller, chibi food sprites falling down lanes, and an unapologetically rainbow art style. Dewey held all ten high scores until Webby unplugged the machine with a Beagle Boy.

This repo is also a prompting experiment: it's being built entirely in [Solo](https://soloterm.com) with no manual code changes. See [the announcement tweet](https://x.com/techenby/status/2097345713311019428?s=20):

> Friends know I'm an anime/animation junkie, and one of my favorites is the 2017 DuckTales reboot. In one episode, the kids play a Guitar Hero-style Japanese import called "Uke or Puke". In trying to get better at prompting, I'm building it all in Solo, no manual code changes.

**PHP:** 8.4  
**Laravel:** 13  
**NativePHP:** [mobile v4](https://nativephp.com/docs/mobile/4) · mobile-ui v0.3  
**Platforms:** iOS · Android (min SDK 33, target/compile SDK 36)  
**Native UI:** SuperNative EDGE — real SwiftUI / Jetpack Compose driven from PHP  
**Database:** SQLite (on-device)  
**Testing:** [Pest v5](https://pestphp.com/docs/installation)  
**Distribution:** TestFlight · Play internal testing  
**Notable Packages:** `nativephp/mobile`, `nativephp/mobile-ui`, `nativephp/mobile-browser`, `laravel/boost`, `laravel/pint`

## Prerequisites

- PHP 8.4
- **iOS:** Xcode with at least one simulator runtime installed
- **Android:** Android Studio with the SDK and an emulator image
- A JDK (Android Studio's bundled one is fine)

Nothing below works without the platform toolchain for whichever OS you're targeting.

## Getting Started

1. Clone repo
2. Set PHP version to 8.4
3. `composer install`
4. `cp .env.example .env`
5. `php artisan key:generate`
6. Fill in `NATIVEPHP_APP_ID` — the bundle identifier (e.g. `com.techenby.ukeorpuke`). Builds fail while it's empty.
7. `php artisan migrate`
8. `php artisan native:install`
9. `php artisan native:run ios` or `php artisan native:run android`

Herd serves the app at `uke-or-puke.test` for tests and tinker, but the app itself only runs on a simulator or device.

## Rebuild vs. hot reload

`php artisan native:watch` (or `native:run <platform> --watch`) picks up Blade and PHP changes live. Native code, plugin, and `config/nativephp.php` changes only compile in at build time — those need a full `native:run`.

## Plugins

Registered in `app/Providers/NativeServiceProvider.php`:

- `NativeUIServiceProvider` — SuperNative EDGE UI
- `BrowserServiceProvider` — system / in-app browser and OAuth sessions
- `AudioServiceProvider` — `packages/uke-audio`, this repo's own plugin: microphone capture and
  on-device note/chord analysis, in Swift for iOS and Kotlin for Android

The two native analyzers are twins and must stay in step — the confidence thresholds they feed live in
PHP, so a divergence means one platform silently rejects chords the other accepts. Both are covered by
suites that run on the host, no device needed:

```
composer test:swift     # add --debug via tests/swift/run.sh --debug for an -Onone build

composer test:kotlin
```

Installing a plugin takes four steps, not one. An installed-but-unregistered plugin fails silently:

```
composer require vendor/plugin-name
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register vendor/plugin-name
php artisan native:plugin:list
```

Then rebuild with `native:run` — native code only links in at build time.

## Seeding

There is no `db:seed` on device. NativePHP runs migrations on app start, so seed data belongs in a migration's `up()`. Seed migrations have to be safe on both fresh installs and existing user databases.

## Design system

`config/native-ui.php` is the single home for the app's look: theme tokens under `theme` (used as `bg-theme-*` / `text-theme-*` / `border-theme-*`) and font aliases under `fonts` (used as `font="pixel"`, `font="headline"`). Add a token there rather than hardcoding a hex in a view.

## Testing

```
php artisan test --compact
```
