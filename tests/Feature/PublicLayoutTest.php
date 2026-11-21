<?php

it('renders the Vietnamese public home with navigation footer and no candidate account requirement', function () {
    $this->get('/')->assertOk()->assertSee('lang="vi"', false)
        ->assertSee('name="viewport"', false)->assertSee(__('public.hero_first'))
        ->assertSee(__('public.hero_second'))->assertSee(__('public.no_candidate_account'))
        ->assertSee('<header', false)->assertSee('<footer', false)->assertSee('href="#noi-dung"', false)
        ->assertSee(route('login'))->assertDontSee('Laravel 13')->assertDontSee('Deploy now');
});

it('keeps public navigation usable without scripts or external fonts', function () {
    $response = $this->get('/')->assertOk();
    $response->assertSee('id="viec-lam"', false)->assertSee('id="cach-ung-tuyen"', false)
        ->assertDontSee('<script', false)->assertDontSee('fonts.bunny.net', false);
    expect($response->headers->get('Content-Security-Policy'))->not->toContain("'unsafe-eval'");
});
