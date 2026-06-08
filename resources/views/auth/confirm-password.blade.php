@extends('layouts.master-without-nav')
@section('title')
    Confirm Password
@endsection
@section('content')
<div class="my-auto">
    <div class="text-center">
        <h5 class="text-gray-600 dark:text-gray-100">Confirm Password !</h5>
        <p class="text-gray-500 dark:text-gray-100/60 mt-1">This is a secure area of the application. Please confirm your password before continuing.</p>
    </div>
    @if (session('status'))
        <div class="">
            {{ session('status') }}
        </div>
    @endif
    <form method="POST" action="{{ route('password.confirm') }}" class="mt-4 pt-2">
        @csrf
        <div class="mb-4">
            <label for="password"
                class="text-gray-600 dark:text-gray-100 font-medium mb-2 block">Password <span class="text-red-600">*</span></label>
            <input type="password" name="password" :value="old('password')"
                class="w-full rounded placeholder:text-sm py-2 border-gray-100 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                id="password" placeholder="Enter password" required>
            @error('password')
                <span class="text-sm text-red-600">{{ $message }}</span>
            @enderror
        </div>
        <div class="mb-3">
            <button
                class="btn border-transparent bg-violet-500 w-full py-2.5 text-white w-100 waves-effect waves-light shadow-md shadow-violet-200 dark:shadow-zinc-600"
                type="submit">Confirm</button>
        </div>
    </form>
    <div class="mt-12 text-center">
    </div>
</div>
@endsection