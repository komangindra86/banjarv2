@props(['name', 'value' => null, 'label' => null, 'help' => 'Ketik nominal, pemisah ribuan otomatis.', 'required' => false, 'placeholder' => 'Rp 0'])
@php($raw = old($name, $value ?? ''))
@php($display = $raw !== '' && $raw !== null ? 'Rp '.number_format((int) $raw, 0, ',', '.') : '')
<label>
    @if($label){{ $label }}@endif
    <input type="text" inputmode="numeric" autocomplete="off" data-rupiah placeholder="{{ $placeholder }}"
        value="{{ $display }}" aria-describedby="{{ $name }}-help">
    <input type="hidden" name="{{ $name }}" data-rupiah-raw value="{{ $raw }}" @if($required) data-rupiah-required @endif>
    @if($help)<span class="help" id="{{ $name }}-help" data-rupiah-hint>{{ $help }}</span>@endif
    <span class="field-error" data-rupiah-error></span>
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</label>
