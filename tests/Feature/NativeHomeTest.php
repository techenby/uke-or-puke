<?php

use Native\Mobile\Testing\Native;

it('renders the first lesson as a native screen with safe areas', function () {
    Native::visit('/')
        ->assertSee('Your first little jam')
        ->assertSee('The rainbow switch')
        ->assertSee('This preview uses visual beats and does not listen.')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'home-screen'
            && ($node['layout']['width'] ?? null) === 'fill'
            && ($node['layout']['height'] ?? null) === 'fill'
            && ($node['layout']['safe_area'] ?? null) === 1);
});

it('resolves the arcade palette for both system appearances', function () {
    Native::visit('/')
        ->assertElement('column', fn (array $node): bool => ($node['ref'] ?? null) === 'home-screen'
            && ($node['style']['bg_color'] ?? null) === '#191722'
            && ($node['props']['dark_bg_color'] ?? $node['style']['bg_color']) === '#191722')
        ->assertElement('pressable', fn (array $node): bool => ($node['ref'] ?? null) === 'speed-normal'
            && ($node['style']['border_color'] ?? null) === '#A9F484');
});

it('labels controls and chord shapes for accessibility', function () {
    Native::visit('/')
        ->assertAccessible()
        ->tap('Meet your two chords')
        ->assertAccessible()
        ->assertSee('C chord. Ring finger on the A string, fret 3. Other strings open.')
        ->tap('A minor')
        ->assertSee('Am chord. Middle finger on the G string, fret 2. Other strings open.');
});

it('opens the lesson within the app without launching a browser', function () {
    Native::fakeBridge();

    Native::visit('/')
        ->tap("Let's jam")
        ->assertNavigatedTo('/arcade')
        ->assertNativeNotCalled('Browser.OpenInApp')
        ->assertNativeNotCalled('Browser.Open');
});
