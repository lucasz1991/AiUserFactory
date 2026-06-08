@extends('layouts.master-without-nav')
@section('title')
    Two Factor Authentication
@endsection
@section('css')
    <link rel="stylesheet" href="{{ URL::asset('build/libs/swiper/swiper-bundle.min.css') }}">
@endsection
@section('content')
    <div class="container-fluid">
        <div class="h-screen md:overflow-hidden">
            <div class="grid grid-cols-1 md:grid-cols-12 ">
                <div class="col-span-12 md:col-span-5 lg:col-span-4 xl:col-span-3 relative z-50">
                    <div class="w-full bg-white xl:p-12 p-10 dark:bg-zinc-800">
                        <div class="flex h-[90vh] flex-col">
                            <div class="mx-auto">
                                <a href="{{ url('index') }}" class="">
                                    <img src="{{ URL::asset('build/images/logo-sm.svg') }}" alt=""
                                        class="h-8 inline"> <span
                                        class="text-xl align-middle font-medium ltr:ml-2 rtl:mr-2 dark:text-white">Minia</span>
                                </a>
                            </div>

                            <div class="my-auto">
                                <div class="text-center">
                                    <h5 class="text-gray-600 dark:text-gray-100">Account Verification</h5>
                                </div>

                                <div x-data="{ recovery: false }">
                                    <div class="mb-4 text-sm text-gray-600 dark:text-zinc-100" x-show="! recovery">
                                        {{ __('Please confirm access to your account by entering the authentication code provided by your authenticator application.') }}
                                    </div>

                                    <div class="mb-4 text-sm text-gray-600" x-cloak x-show="recovery">
                                        {{ __('Please confirm access to your account by entering one of your emergency recovery codes.') }}
                                    </div>

                                    <form method="POST" action="{{ route('two-factor.login') }}">
                                        @csrf

                                        <div class="mt-4" x-show="! recovery">
                                            <label for="code"
                                                class="block font-medium text-sm text-gray-700 dark:text-zinc-100">Code</label>
                                            <input id="code" class="block mt-1 w-full dark:bg-zinc-700 dark:border-transparent dark:text-gray-100" type="text"
                                                inputmode="numeric" name="code" autofocus x-ref="code"
                                                autocomplete="one-time-code" placeholder="Enter your code"
                                                class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            @error('code')
                                                <span class="text-sm text-red-600">{{ $message }}</span>
                                            @enderror
                                        </div>

                                        <div class="mt-4" x-cloak x-show="recovery">
                                            <label for="recovery_code"
                                                class="block font-medium text-sm text-gray-700 dark:text-zinc-100">Recovery</label>
                                            <input id="recovery_code" class="block mt-1 w-full dark:bg-zinc-700 dark:border-transparent dark:text-gray-100" type="text"
                                                name="recovery_code" x-ref="recovery_code" autocomplete="one-time-code" placeholder="Enter recovery code"
                                                class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                            @error('recovery_code')
                                                <span class="text-sm text-red-600">{{ $message }}</span>
                                            @enderror
                                        </div>

                                        <div class="flex items-center justify-end mt-4">
                                            <button type="button"
                                                class="text-sm text-gray-600 hover:text-gray-900 underline cursor-pointer"
                                                x-show="! recovery"
                                                x-on:click="
                                                                recovery = true;
                                                                $nextTick(() => { $refs.recovery_code.focus() })
                                                            ">
                                                {{ __('Use a recovery code') }}
                                            </button>

                                            <button type="button"
                                                class="text-sm text-gray-600 hover:text-gray-900 underline cursor-pointer"
                                                x-cloak x-show="recovery"
                                                x-on:click="
                                                                recovery = false;
                                                                $nextTick(() => { $refs.code.focus() })
                                                            ">
                                                {{ __('Use an authentication code') }}
                                            </button>

                                            <button type="submit"
                                                class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150 ml-4 dark:bg-gray-600">
                                                Log in
                                            </button>

                                        </div>
                                    </form>
                                </div>
                            </div>


                            <div class=" text-center">
                                <p class="text-gray-500 dark:text-gray-100 relative mb-5">©
                                    <script>
                                        document.write(new Date().getFullYear())
                                    </script> Minia . Crafted with <i class="mdi mdi-heart text-red-400"></i>
                                    by Themesbrand
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-span-12 md:col-span-7 lg:col-span-8 xl:col-span-9">
                    <div class="h-screen bg-cover relative p-5 bg-[url('../images/auth-bg.jpg')]">
                        <div class="absolute inset-0 bg-violet-500/90"></div>

                        <ul class="bg-bubbles absolute top-0 left-0 w-full h-full overflow-hidden animate-square">
                            <li class="h-10 w-10 rounded-3xl bg-white/10 absolute left-[10%] "></li>
                            <li class="h-28 w-28 rounded-3xl bg-white/10 absolute left-[20%]"></li>
                            <li class="h-10 w-10 rounded-3xl bg-white/10 absolute left-[25%]"></li>
                            <li class="h-20 w-20 rounded-3xl bg-white/10 absolute left-[40%]"></li>
                            <li class="h-24 w-24 rounded-3xl bg-white/10 absolute left-[70%]"></li>
                            <li class="h-32 w-32 rounded-3xl bg-white/10 absolute left-[70%]"></li>
                            <li class="h-36 w-36 rounded-3xl bg-white/10 absolute left-[32%]"></li>
                            <li class="h-20 w-20 rounded-3xl bg-white/10 absolute left-[55%]"></li>
                            <li class="h-12 w-12 rounded-3xl bg-white/10 absolute left-[25%]"></li>
                            <li class="h-36 w-36 rounded-3xl bg-white/10 absolute left-[90%]"></li>
                        </ul>

                        <div class="grid grid-cols-12 content-center h-screen">
                            <div class="col-span-8 col-start-3">
                                <div class="swiper login-slider">
                                    <div class="swiper-wrapper">
                                        <div class="swiper-slide">
                                            <i class="bx bxs-quote-alt-left text-green-600 text-5xl"></i>
                                            <h3 class="mt-4 text-white text-22">“I feel confident imposing change on
                                                myself. It's a lot more progressing fun than looking back. That's why I
                                                ultricies enim at malesuada nibh diam on tortor neaded to throw curve
                                                balls.”</h3>
                                            <div class="flex mt-6 mb-10 pt-4">
                                                <img src="{{ URL::asset('build/images/users/avatar-1.jpg') }}"
                                                    class="h-12 w-12 rounded-full" alt="...">
                                                <div class="flex-1 ltr:ml-3 rtl:mr-2 mb-4">
                                                    <h5 class="font-size-18 text-white">Ilse R. Eaton</h5>
                                                    <p class="mb-0 text-white/50">Manager
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="swiper-slide">
                                            <i class="bx bxs-quote-alt-left text-green-600 text-5xl"></i>
                                            <h3 class="mt-4 text-white text-22">“I feel confident imposing change on
                                                myself. It's a lot more progressing fun than looking back. That's why I
                                                ultricies enim at malesuada nibh diam on tortor neaded to throw curve
                                                balls.”</h3>
                                            <div class="flex mt-6 mb-10 pt-4">
                                                <img src="{{ URL::asset('build/images/users/avatar-2.jpg') }}"
                                                    class="h-12 w-12 rounded-full" alt="...">
                                                <div class="flex-1 ltr:ml-3 rtl:mr-2 mb-4">
                                                    <h5 class="font-size-18 text-white">Mariya Willam</h5>
                                                    <p class="mb-0 text-white/50">Designer
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="swiper-slide">
                                            <i class="bx bxs-quote-alt-left text-green-600 text-5xl"></i>
                                            <h3 class="mt-4 text-white text-22">“I feel confident imposing change on
                                                myself. It's a lot more progressing fun than looking back. That's why I
                                                ultricies enim at malesuada nibh diam on tortor neaded to throw curve
                                                balls.”</h3>
                                            <div class="flex mt-6 mb-10 pt-4">
                                                <img src="{{ URL::asset('build/images/users/avatar-3.jpg') }}"
                                                    class="h-12 w-12 rounded-full" alt="...">
                                                <div class="flex-1 ltr:ml-3 rtl:mr-2 mb-4">
                                                    <h5 class="font-size-18 text-white">Jiya Jons</h5>
                                                    <p class="mb-0 text-white/50">Developer
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="swiper-pagination"></div>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
@section('scripts')
    <script src="{{ URL::asset('build/libs/swiper/swiper-bundle.min.js') }}"></script>

    <script src="{{ URL::asset('build/js/pages/login.init.js') }}"></script>

    <script src="{{ URL::asset('build/js/app.js') }}"></script>
@endsection
