@props(['url', 'logo' => null])
{{-- City identity instead of the framework's logo. $logo is a cid: reference embedded in the email itself: a
     link to the DMS server would not load for clients outside the city network. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" width="64" height="64" alt="Official Seal of the City of San Fernando" style="display: block; width: 64px; height: 64px; margin: 0 auto 8px;">
@endif
City Government of San Fernando
</a>
</td>
</tr>
