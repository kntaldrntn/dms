<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('certificates');
    $office = Department::create(['code' => 'ADM', 'name' => 'City Administrator', 'state' => 1]);
    $this->signer = User::factory()->create(['role' => 'city_administrator', 'department_id' => $office->id]);
});

function certificateUpload(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('signer.p12', $contents);
}

test('a signer uploads their own certificate; only its details are kept and the PIN is not', function () {
    $this->actingAs($this->signer)->post(route('certificate.update'), [
        'certificate' => certificateUpload(testCertificate('2468', 'JUAN DELA CRUZ')), 'certificate_pin' => '2468',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $signer = $this->signer->fresh();
    expect($signer->certificate_path)->toBe("users/{$signer->id}.p12")
        ->and($signer->certificate_subject)->toBe('JUAN DELA CRUZ')
        ->and($signer->hasValidCertificate())->toBeTrue()
        ->and($signer->toArray())->not->toHaveKey('certificate_path');
    Storage::disk('certificates')->assertExists($signer->certificate_path);
    expect(json_encode($signer->getAttributes()))->not->toContain('2468');

    $this->get(route('certificate.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('certificate.subject', 'JUAN DELA CRUZ')->where('certificate.is_valid', true));
});

test('a wrong PIN, a non-certificate file or an expired certificate is refused and nothing is saved', function (Closure $upload, string $error) {
    $this->actingAs($this->signer)->post(route('certificate.update'), $upload())
        ->assertSessionHasErrors($error)
        // The PIN is never flashed back into the session.
        ->assertSessionMissing('_old_input.certificate_pin');

    expect($this->signer->fresh()->certificate_path)->toBeNull();
    expect(Storage::disk('certificates')->allFiles())->toBe([]);
})->with([
    'wrong PIN' => [fn () => ['certificate' => certificateUpload(testCertificate('2468')), 'certificate_pin' => '0000'], 'certificate_pin'],
    'not a certificate' => [fn () => ['certificate' => certificateUpload('not a p12'), 'certificate_pin' => '2468'], 'certificate_pin'],
    'wrong file type' => [fn () => ['certificate' => UploadedFile::fake()->create('signer.pdf', 5), 'certificate_pin' => '2468'], 'certificate'],
    'expired' => [function () {
        $contents = testCertificate('2468', 'OLD', 0);
        test()->travel(2)->days();

        return ['certificate' => certificateUpload($contents), 'certificate_pin' => '2468'];
    }, 'certificate'],
]);

test('only approvers upload certificates, and a signer can remove theirs', function () {
    $staff = User::factory()->create(['role' => 'staff', 'department_id' => $this->signer->department_id]);
    $this->actingAs($staff)->post(route('certificate.update'), [
        'certificate' => certificateUpload(testCertificate('2468')), 'certificate_pin' => '2468',
    ])->assertForbidden();
    $this->delete(route('certificate.destroy'))->assertForbidden();

    $this->actingAs($this->signer)->post(route('certificate.update'), [
        'certificate' => certificateUpload(testCertificate('2468')), 'certificate_pin' => '2468',
    ])->assertSessionHasNoErrors();
    $path = $this->signer->fresh()->certificate_path;

    $this->delete(route('certificate.destroy'))->assertRedirect();
    expect($this->signer->fresh()->certificate_path)->toBeNull();
    Storage::disk('certificates')->assertMissing($path);
});
