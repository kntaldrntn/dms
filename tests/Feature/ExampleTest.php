<?php

test('redirects guests to login from the home route', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login', absolute: false));
});
