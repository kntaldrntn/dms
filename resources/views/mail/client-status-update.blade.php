<x-mail::message>
# Document status update

@if ($name)
Good day, {{ $name }}.
@else
Good day.
@endif

Here is the latest status of the document you submitted to the City Government of San Fernando.

<x-mail::panel>
**Barcode:** {{ $barcode }}<br>
@if ($subject)
**Subject:** {{ $subject }}<br>
@endif
**Status:** {{ $status }}<br>
**As of:** {{ $updatedAt }}
</x-mail::panel>

Please keep your barcode for reference when you follow up with the Records Section.

This is an automated message from the City Government of San Fernando Document Management System. Please do not reply to this email.
</x-mail::message>
