@props(['for'])

<span x-data="{hasError: '{{$errors->get($for)[0] ?? ''}}' }"
     x-init="()=> { $watch('hasError', (value)=> {
            let errorDiv = document.getElementsByClassName('invalid-feedback')[0];
            if(errorDiv){
                errorDiv.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'nearest'});
            }
    })}">
    @error($for)
        <span {{ $attributes->merge(['class' => 'text-red-500 text-sm invalid-feedback']) }} role="alert" >
            {{ $message }}
        </span>
    @enderror
</span>