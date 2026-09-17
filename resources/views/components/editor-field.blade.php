@props(['name', 'label', 'value' => null, 'type' => 'text', 'required' => false, 'options' => [], 'help' => null])
<label for="field-{{ $name }}">{{ $label }}{{ $required ? ' *' : '' }}
@if($type === 'textarea')
<textarea id="field-{{ $name }}" name="{{ $name }}" rows="5" @required($required) @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>{{ old($name, $value) }}</textarea>
@elseif($type === 'select')
<select id="field-{{ $name }}" name="{{ $name }}" @required($required)>@foreach($options as $key => $text)<option value="{{ $key }}" @selected((string) old($name, $value) === (string) $key)>{{ $text }}</option>@endforeach</select>
@else
<input id="field-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($name, $value) }}" @required($required) @if($type === 'number') step="any" @endif @if($errors->has($name)) aria-invalid="true" aria-describedby="error-{{ $name }}" @endif>
@endif
@if($help)<span class="help">{{ $help }}</span>@endif
@error($name)<span id="error-{{ $name }}" class="field-error">{{ $message }}</span>@enderror
</label>
