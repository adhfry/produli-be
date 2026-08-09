@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table border="0" cellpadding="0" cellspacing="0" role="presentation" style="margin: 0 auto;">
<tr>
<td style="vertical-align: middle;">
<img src="{{ \App\Support\MailBranding::logoDataUri() ?? '' }}" class="logo" alt="{{ config('app.name') }}">
</td>
</tr>
</table>
</a>
</td>
</tr>
