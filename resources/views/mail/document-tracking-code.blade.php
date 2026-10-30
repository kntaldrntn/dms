{{-- $message exists only while the email is actually sent; a preview or test render falls back to text only.
     Embedded once into a variable: Blade evaluates a component attribute expression twice, which attached the
     seal twice. --}}
@php($seal = isset($message) ? $message->embed(public_path('images/san_fernando_logo.png')) : null)
<x-mail::message :logo="$seal">
# We received your document

@if ($name)
Good day, {{ $name }}.
@else
Good day.
@endif

The Records Section of the City Government of San Fernando received your document on {{ $receivedAt }}.

<x-mail::panel>
**Barcode:** {{ $barcode }}<br>
**Access code:** {{ $accessCode }}<br>
@if ($subject)
**Subject:** {{ $subject }}
@endif
</x-mail::panel>

To see the latest status of your document at any time, open the Document Management System, choose **Track a document**, and enter your barcode and access code.

<x-mail::button :url="$trackUrl">
Track my document
</x-mail::button>

Keep this email private: anyone with both codes can see the status of your document.

This is an automated message from the City Government of San Fernando Document Management System. Please do not reply to this email.
</x-mail::message>
