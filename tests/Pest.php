<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A throwaway self-signed .p12 (PKCS#12) protected by $pin, valid for $days (negative = already expired).
 * PHP on Windows needs an explicit openssl.cnf to generate keys; OPENSSL_CONF or the XAMPP copy is used.
 */
function testCertificate(string $pin, string $commonName = 'TEST SIGNER', int $days = 30): string
{
    $configFile = getenv('OPENSSL_CONF') ?: 'C:/xampp/apache/conf/openssl.cnf';
    $config = is_file($configFile) ? ['config' => $configFile] : [];
    $key = openssl_pkey_new($config + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $request = openssl_csr_new(['commonName' => $commonName], $key, $config);
    // openssl_csr_sign() cannot back-date, so an expired certificate is signed for 0 days and checked after expiry.
    $certificate = openssl_csr_sign($request, null, $key, max(0, $days), $config);
    openssl_pkcs12_export($certificate, $pkcs12, $key, $pin);

    return $pkcs12;
}
