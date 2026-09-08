<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Nativephp\NativeUi\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 17 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Color tokens accept:
    |   - CSS hex: '#B91C1C', '#F00', or with alpha '#8B5CF680' (#RRGGBBAA)
    |   - Tailwind palette names: 'red-300', 'orange-800'
    |   - Opacity modifiers on either: 'red-300/20', '#8B5CF6/50'
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        'light' => [
            'primary' => '#A9F484',
            'on-primary' => '#18211A',
            'secondary' => '#363044',
            'on-secondary' => '#FAF5EB',
            'surface' => '#262333',
            'on-surface' => '#FAF5EB',
            'background' => '#191722',
            'on-background' => '#FAF5EB',
            'surface-variant' => '#302B3F',
            'on-surface-variant' => '#C0B9CD',
            'outline' => '#494153',
            'destructive' => '#FF8EAD',
            'on-destructive' => '#251C27',
            'accent' => '#FFD776',
            'on-accent' => '#251C27',
            'pink' => '#FF8EAD',
            'orange' => '#FFB87A',
            'sun' => '#FFD776',
            'mint' => '#A9F484',
            'sky' => '#8DDDF4',
            'violet' => '#C4AAFA',
            'ink' => '#191722',
        ],

        'dark' => [
            'primary' => '#A9F484',
            'on-primary' => '#18211A',
            'secondary' => '#363044',
            'on-secondary' => '#FAF5EB',
            'surface' => '#262333',
            'on-surface' => '#FAF5EB',
            'background' => '#191722',
            'on-background' => '#FAF5EB',
            'surface-variant' => '#302B3F',
            'on-surface-variant' => '#C0B9CD',
            'outline' => '#494153',
            'destructive' => '#FF8EAD',
            'on-destructive' => '#251C27',
            'accent' => '#FFD776',
            'on-accent' => '#251C27',
            'pink' => '#FF8EAD',
            'orange' => '#FFB87A',
            'sun' => '#FFD776',
            'mint' => '#A9F484',
            'sky' => '#8DDDF4',
            'violet' => '#C4AAFA',
            'ink' => '#191722',
        ],

        // Corner radii (points / dp).
        'radius-sm' => 4,
        'radius-md' => 8,
        'radius-lg' => 16,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,
    ],

    'fonts' => [
        'default' => 'System',
        'headline' => 'Archivo+Black-Regular',
        'pixel' => 'PressStart2P-Regular',
        'lobster' => 'Lobster+Two-Regular',
    ],

];
