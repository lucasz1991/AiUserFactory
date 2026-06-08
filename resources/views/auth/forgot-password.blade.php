@extends('layouts.master-without-nav')
@section('title')
    Forget Password
@endsection
@section('content')
                            <div class="my-auto">
                                <div class="text-center mb-8">
                                    <h5 class="text-gray-600 dark:text-gray-100">Reset Password</h5>
                                    <p class="text-gray-500 mt-1 dark:text-zinc-100/60">Reset Password with Minia.</p>
                                </div>

                                <div class="px-5 py-3 bg-green-500/10  border-2 border-green-500/30 rounded">
                                    <p class="text-green-500">Enter your Email and instructions will be sent to you!</p> 
                                </div>

                                @if (session('status'))
                                    <div class="mb-4 font-medium text-sm text-green-600">
                                        {{ session('status') }}
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('password.email') }}" class="mt-4 pt-2">
                                    @csrf
                                    <div class="mb-6">
                                        <label class="text-gray-600 font-medium mb-2 block dark:text-gray-100">Email <span class="text-red-600">*</span></label>
                                        <input type="email" name="email" :value="old('email')" required
                                            class="w-full border-gray-100 rounded placeholder:text-sm py-2 placeholder:text-gray-400 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                                            id="email" placeholder="Enter email">
                                            @error('email')
                                            <span class="text-sm text-red-600">{{ $message }}</span>
                                        @enderror
                                    </div>

                                    <div class="mb-4">
                                        <button
                                            class="btn border-transparent bg-violet-500 w-full py-2.5 text-white w-100 waves-effect waves-light shadow-md shadow-violet-200 dark:shadow-zinc-600"
                                            type="submit">Reset</button>
                                    </div>
                                </form>

                                <div class="mt-12 text-center">
                                    <p class="text-gray-500 dark:text-zinc-100">Remember It ? <a href="{{ route('login') }}"
                                            class="text-violet-500 font-semibold"> Sign In </a> </p>
                                </div>
                            </div>

@endsection
