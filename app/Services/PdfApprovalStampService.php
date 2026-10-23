<?php

namespace App\Services;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use setasign\Fpdi\Tcpdf\Fpdi;

class PdfApprovalStampService
{
    /** The office the signer approves for: the stamp's title and the PDF signature's location and reason. */
    public const OCM = ['code' => 'ocm', 'title' => 'City Mayor',
        'location' => 'Office of the City Mayor, San Fernando, La Union', 'reason' => 'OCM approval/signature'];

    public const ADM = ['code' => 'adm', 'title' => 'City Administrator',
        'location' => 'Office of the City Administrator, San Fernando, La Union', 'reason' => 'City Administrator approval'];

    public function __construct(private SigningCertificateService $certificates) {}

    /**
     * Stamp the signer's e-signature on the PDF and sign it with the signer's own PNPKI certificate (FGD Phase 2c-2).
     * The PIN opens the certificate in memory for this signing only.
     *
     * @param  array{page:int,x:float,y:float,width:float,height:float}  $placement
     * @param  array{code:string,title:string,location:string,reason:string}  $office
     */
    public function stamp(Document $document, User $signer, array $placement, array $office, string $pin): string
    {
        if (! $signer->signature_path || ! Storage::disk('public')->exists($signer->signature_path)) {
            throw ValidationException::withMessages(['stamp_placement' => 'Your e-signature is not on file. Ask the System Administrator to upload it in User Management, then approve again.']);
        }
        $credentials = $this->certificates->credentials($signer, $pin);
        $sourcePath = $this->sourcePath($document);
        if (! is_string($sourcePath) || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new RuntimeException('The source PDF to sign is unavailable.');
        }

        $sourcePdf = tempnam(sys_get_temp_dir(), 'approval-');
        if ($sourcePdf === false || ! copy($sourcePath, $sourcePdf)) {
            throw new RuntimeException('Unable to prepare the uploaded PDF for signing.');
        }

        $cleanSignature = null;
        // TCPDF reads intermediate certificates (the PNPKI chain) from a file, so it lives in a temp file briefly.
        $chainFile = null;
        try {
            $cleanSignature = $this->cleanSignatureImage(Storage::disk('public')->path($signer->signature_path));
            if ($credentials['extracerts'] !== []) {
                $chainFile = tempnam(sys_get_temp_dir(), 'chain-');
                if ($chainFile === false || file_put_contents($chainFile, implode("\n", $credentials['extracerts'])) === false) {
                    throw new RuntimeException('Unable to prepare the certificate chain for signing.');
                }
            }
            $this->configureTcpdfCache();
            $pdf = new Fpdi('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator(config('app.name'));
            $pdf->SetTitle(strtoupper($office['code'])." Approved Document {$document->barcode}");
            $pdf->SetPrintHeader(false);
            $pdf->SetPrintFooter(false);
            $pdf->SetAutoPageBreak(false, 0);

            $pageCount = $pdf->setSourceFile($sourcePdf);
            if ($placement['page'] < 1 || $placement['page'] > $pageCount) {
                throw new RuntimeException('The selected signature page is no longer available.');
            }

            foreach (range(1, $pageCount) as $pageNumber) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($template);

                if ($pageNumber === $placement['page']) {
                    $x = $size['width'] * $placement['x'] / 1000;
                    $y = $size['height'] * $placement['y'] / 1000;
                    $width = $size['width'] * $placement['width'] / 1000;
                    $height = $size['height'] * $placement['height'] / 1000;
                    $this->drawVisibleStamp($pdf, $cleanSignature, $x, $y, $width, $height, $credentials['subject'], $office['title']);
                    $pdf->setSignatureAppearance($x, $y, $width, $height, $pageNumber, strtoupper($office['code']).' Digital Signature');
                }
            }

            // PEM strings, not files: the private key is never written to disk.
            $pdf->setSignature($credentials['cert'], $credentials['pkey'], '', $chainFile ?? '', 2, [
                'Name' => $signer->name, 'Location' => $office['location'], 'Reason' => $office['reason'],
            ]);

            $path = "signed-documents/{$document->barcode}-{$office['code']}-approved-".now('Asia/Manila')->format('YmdHis').'.pdf';
            Storage::disk('local')->put($path, $pdf->Output(basename($path), 'S'));

            return $path;
        } finally {
            @unlink($sourcePdf);
            if ($cleanSignature) {
                @unlink($cleanSignature);
            }
            if ($chainFile) {
                @unlink($chainFile);
            }
        }
    }

    /** Stamp the newest signed version, so a later approval (OCM after ADM) keeps the earlier signature. */
    private function sourcePath(Document $document): ?string
    {
        $signed = $document->trails()->getQuery()->reorder()->whereNotNull('signed_file_path')->latest('id')->value('signed_file_path');
        if ($signed && strtolower(pathinfo($signed, PATHINFO_EXTENSION)) === 'pdf' && Storage::disk('local')->exists($signed)) {
            return Storage::disk('local')->path($signed);
        }

        return $document->attached_file ? Storage::disk('public')->path($document->attached_file) : null;
    }

    private function cleanSignatureImage(string $path): string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            throw new RuntimeException('GD is required to prepare the signature image.');
        }
        // Uploaded e-signatures may be PNG or JPEG.
        $image = @imagecreatefromstring((string) file_get_contents($path));
        $temporaryPath = tempnam(sys_get_temp_dir(), 'ocm-signature-');
        if ($image === false || $temporaryPath === false) {
            throw new RuntimeException('Unable to prepare the certificate signature image.');
        }

        $clean = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($clean === false) {
            imagedestroy($image);
            throw new RuntimeException('Unable to prepare the certificate signature image.');
        }

        imagealphablending($clean, false);
        imagesavealpha($clean, true);
        imagefill($clean, 0, 0, imagecolorallocatealpha($clean, 255, 255, 255, 127));

        // Keep only the visible ink, normalize it to black, and retain a transparent background.
        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 0; $x < imagesx($image); $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $sourceAlpha = ($pixel >> 24) & 0x7F;
                $red = ($pixel >> 16) & 0xFF;
                $green = ($pixel >> 8) & 0xFF;
                $blue = $pixel & 0xFF;
                $brightness = ($red * 0.299) + ($green * 0.587) + ($blue * 0.114);
                $inkStrength = ((127 - $sourceAlpha) / 127) * ((255 - $brightness) / 255);

                if ($inkStrength > 0.08) {
                    $alpha = max(0, 127 - (int) round(min(1, $inkStrength * 1.35) * 127));
                    imagesetpixel($clean, $x, $y, imagecolorallocatealpha($clean, 0, 0, 0, $alpha));
                }
            }
        }

        if (! @imagepng($clean, $temporaryPath)) {
            imagedestroy($image);
            imagedestroy($clean);
            throw new RuntimeException('Unable to prepare the certificate signature image.');
        }
        imagedestroy($image);
        imagedestroy($clean);

        return $temporaryPath;
    }

    private function drawVisibleStamp(Fpdi $pdf, string $signaturePath, float $x, float $y, float $width, float $height, string $name, string $title): void
    {
        $signatureWidth = $width * 0.48;
        $textX = $x + $signatureWidth + ($width * 0.03);
        $textY = $y + ($height * 0.16);
        $textWidth = $width - ($textX - $x) - ($width * 0.03);
        $signatureHeight = min($height * 0.62, $signatureWidth / 2.64);
        $signatureY = $y + (($height - $signatureHeight) / 2);

        $lines = [
            $name,
            $title,
            'Date: '.now('Asia/Manila')->format('Y-m-d H:i:s'),
        ];

        // Shrink the text so the longest line (usually the certificate name) stays inside the stamp box
        // instead of running past the page edge.
        $widest = max($pdf->GetStringWidth('Digitally Signed', 'helvetica', 'B', 7.5), ...array_map(
            fn (string $line) => $pdf->GetStringWidth($line, 'helvetica', '', 7.2),
            $lines,
        ));
        $scale = $widest > $textWidth ? max(0.6, $textWidth / $widest) : 1;
        $lineHeight = 3.5 * $scale;

        $pdf->Image($signaturePath, $x, $signatureY, $signatureWidth, $signatureHeight, 'PNG');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 7.5 * $scale);
        $pdf->Text($textX, $textY, 'Digitally Signed');
        $pdf->SetFont('helvetica', '', 7.2 * $scale);
        foreach ($lines as $index => $line) {
            $pdf->Text($textX, $textY + ($lineHeight * ($index + 1)), $line);
        }
    }

    private function configureTcpdfCache(): void
    {
        if (defined('K_PATH_CACHE')) {
            return;
        }
        $directory = storage_path('framework/cache/tcpdf');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to prepare the PDF signing cache.');
        }
        define('K_PATH_CACHE', str_replace('\\', '/', $directory).'/');
    }
}
