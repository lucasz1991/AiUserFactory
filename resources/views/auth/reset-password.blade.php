@extends('layouts.master-without-nav')
@section('title')
    Reset Password
@endsection
@section('content')
<div class="my-auto">
                                <div class="text-center">
                                    <h5 class="text-gray-600 dark:text-gray-100">Welcome Back !</h5>
                                    <p class="text-gray-500 dark:text-gray-100/60 mt-1">Sign in to continue to Minia.</p>
                                </div>
                                @if (session('status'))
                                    <div class="">
                                        {{ session('status') }}
                                    </div>
                                @endif

                                <form method="POST" action="{{ route('password.update') }}" class="mt-4 pt-2">
                                    @csrf

                                    <input type="hidden" name="token" value="{{ $request->route('token') }}">
                                    <div class="mb-4">
                                        <label for="email"
                                            class="text-gray-600 dark:text-gray-100 font-medium mb-2 block">Email <span class="text-red-600">*</span></label>
                                        <input type="email" name="email" :value="old('email', $request->email)"
                                            class="w-full rounded placeholder:text-sm py-2 border-gray-100 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                                            id="email" placeholder="Enter email" required>
                                        @error('email')
                                            <span class="text-sm text-red-600">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="mb-3">
                                        <div>
                                            <div class="flex-grow-1">
                                                <label for="password"
                                                    class="text-gray-600 font-medium mb-2 block dark:text-gray-100">Password
                                                    <span class="text-red-600">*</span></label>
                                            </div>
                                        </div>

                                        <div class="flex">
                                            <input type="password" id="password" name="password" required
                                                class="w-full border-gray-100 rounded ltr:rounded-r-none rtl:rounded-l-none placeholder:text-sm py-2 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                                                placeholder="Enter password" aria-label="Password"
                                                aria-describedby="password-addon">
                                            <button
                                                class="bg-gray-50 px-4 rounded ltr:rounded-l-none rtl:rounded-r-none border border-gray-100 ltr:border-l-0 rtl:border-r-0 dark:bg-zinc-700 dark:border-zinc-600 dark:text-gray-100"
                                                type="button" id="password-addon"><i
                                                    class="mdi mdi-eye-outline"></i></button>
                                        </div>
                                        @error('password')
                                            <span class="text-sm text-red-600">{{ $message }}</span>
                                        @enderror
                                    </div>
                                    <div class="mb-3">
                                        <div>
                                            <div class="flex-grow-1">
                                                <label for="password_confirmation"
                                                    class="text-gray-600 font-medium mb-2 block dark:text-gray-100">Confirm
                                                    Password <span class="text-red-600">*</span></label>
                                            </div>
                                        </div>

                                        <div class="flex">
                                            <input type="password" id="password_confirmation" name="password_confirmation"
                                                required
                                                class="w-full border-gray-100 rounded ltr:rounded-r-none rtl:rounded-l-none placeholder:text-sm py-2 dark:bg-zinc-700/50 dark:border-zinc-600 dark:text-gray-100 dark:placeholder:text-zinc-100/60"
                                                placeholder="Enter password" aria-label="Password"
                                                aria-describedby="password-addon">
                                            <button
                                                class="bg-gray-50 px-4 rounded ltr:rounded-l-none rtl:rounded-r-none border border-gray-100 ltr:border-l-0 rtl:border-r-0 dark:bg-zinc-700 dark:border-zinc-600 dark:text-gray-100"
                                                type="button" id="password-addon"><i
                                                    class="mdi mdi-eye-outline"></i></button>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <button
                                            class="btn border-transparent bg-violet-500 w-full py-2.5 text-white w-100 waves-effect waves-light shadow-md shadow-violet-200 dark:shadow-zinc-600"
                                            type="submit">Log In</button>
                                    </div>
                                </form>

                                <div class="mt-4 pt-2 text-center">
                                    <div>
                                        <h6 class="text-14 mb-3 text-gray-500 dark:text-gray-100 font-medium">- Sign in with
                                            -</h6>
                                    </div>

                                    <div class="flex justify-center gap-3">
                                        <a href="" class="h-9 w-9 bg-violet-500 leading-[2.9] rounded-full">
                                            <i class="mdi mdi-facebook text-lg text-white"></i>
                                        </a>
                                        <a href="" class="h-9 w-9 bg-sky-500 leading-[2.9] rounded-full">
                                            <i class="mdi mdi-twitter text-lg text-white"></i>
                                        </a>
                                        <a href="" class="h-9 w-9 bg-red-400 leading-[2.9] rounded-full">
                                            <i class="mdi mdi-google text-lg text-white"></i>
                                        </a>
                                    </div>
                                </div>

                                <div class="mt-12 text-center">
                                    <p class="text-gray-500 dark:text-gray-100">Don't have an account ? <a
                                            href="{{ route('register') }}" class="text-violet-500 font-semibold"> Signup now </a> </p>
                                </div>
                            </div>
@endsection