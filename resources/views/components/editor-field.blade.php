@props(['name', 'label', 'value' => null, 'type' => 'text', 'required' => false, 'options' => [], 'help' => null])
@php
    // Isian lama berupa array (kiriman yang diutak-atik) diabaikan agar formulir tetap bisa dibuka.
    $current = old($name, $value);
    $current = is_array($current) ? $value : $current;
@endphp
<label for="field-{{ $name }}">{{ $label }}{{ $required ? ' *' : '' }}
@if($type === 'textarea')
<textarea id="field-{{ $name }}" name="{{ $name }}" rows="5" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ $current }}</textarea>
@elseif($type === 'select')
<select id="field-{{ $name }}" name="{{ $name }}" @required($required)>@foreach($options as $key => $text)<option value="{{ $key }}" @selected((string) $current === (string) $key)>{{ $text }}</option>@endforeach</select>
@else
<input id="field-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}" @required($required) @if($type === 'number') step="any" @endif @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
@endif
@if($help)<span class="help">{{ $help }}</span>@endif
@error($name)<span id="error-{{ $name }}" class="field-error">{{ $message }}</span>@enderror
</label>
