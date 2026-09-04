@php($card = 'w-full bg-white dark:bg-[#1C1C1E]')
@php($label = 'flex-1 text-base text-zinc-900 dark:text-zinc-50')
@php($hint = 'text-xs text-zinc-500 dark:text-zinc-400 px-4 pb-3 -mt-1')
@php($section = 'text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase px-4 pb-1.5')

<column class="w-full h-full bg-zinc-50 dark:bg-black">
    <scroll-view class="flex-1 w-full">
        <column class="w-full gap-6 py-4">

            {{-- Profile --}}
            <row class="w-full items-center gap-4 px-4">
                <column class="w-16 h-16 rounded-full items-center justify-center bg-[{{ $accent }}]">
                    <text class="text-xl font-bold text-white">{{ $initials }}</text>
                </column>
                <column class="flex-1 gap-0.5">
                    <text class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ $name }}</text>
                    <text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $handle }}</text>
                    @if ($tagline)
                        <text class="text-sm text-zinc-400 dark:text-zinc-500" max-lines="1">{{ $tagline }}</text>
                    @endif
                </column>
            </row>

            {{-- Privacy --}}
            <column class="w-full">
                <text class="{{ $section }}">Privacy</text>
                <column class="{{ $card }}">
                    <row class="w-full items-center gap-3 px-4 py-3">
                        <column class="w-8 h-8 rounded-full items-center justify-center bg-[#31D158]">
                            <icon name="dot.radiowaves.left.and.right" size="16" color="#FFFFFF" />
                        </column>
                        <text class="{{ $label }}">Show active status</text>
                        <toggle value="{{ $activeStatus ? '1' : '' }}" @change="toggleActiveStatus" />
                    </row>
                    <text class="{{ $hint }}">
                        When off, no one sees when you’re active — and you won’t see anyone else’s status either.
                    </text>

                    <divider class="w-full ml-16" />

                    <row class="w-full items-center gap-3 px-4 py-3">
                        <column class="w-8 h-8 rounded-full items-center justify-center bg-[#0A7CFF]">
                            <icon name="checkmark.circle.fill" size="16" color="#FFFFFF" />
                        </column>
                        <text class="{{ $label }}">Read receipts</text>
                        <toggle value="{{ $readReceipts ? '1' : '' }}" @change="toggleReadReceipts" />
                    </row>
                    <text class="{{ $hint }}">
                        When off, others won’t see “Seen” on your messages — and you won’t see theirs.
                    </text>
                </column>
            </column>

            {{-- Notifications --}}
            <column class="w-full">
                <text class="{{ $section }}">Notifications</text>
                <column class="{{ $card }}">
                    <row class="w-full items-center gap-3 px-4 py-3">
                        <column class="w-8 h-8 rounded-full items-center justify-center bg-[#FF375F]">
                            <icon name="bell.fill" size="16" color="#FFFFFF" />
                        </column>
                        <column class="flex-1 gap-0.5">
                            <text class="{{ $label }}">Push notifications</text>
                            <text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $pushState }}</text>
                        </column>
                        @if ($pushOn)
                            <icon name="checkmark.circle.fill" size="22" color="#31D158" />
                        @elseif ($pushBlocked)
                            <pressable @tap="openSystemSettings">
                                <column class="rounded-full bg-zinc-100 dark:bg-[#2C2C2E] px-3 py-1.5">
                                    <text class="text-xs font-semibold text-[#0A7CFF]">Open Settings</text>
                                </column>
                            </pressable>
                        @else
                            <pressable @tap="enablePush">
                                <column class="rounded-full bg-[#0A7CFF] px-3 py-1.5">
                                    <text class="text-xs font-semibold text-white">Turn on</text>
                                </column>
                            </pressable>
                        @endif
                    </row>
                </column>
            </column>

            {{-- Appearance --}}
            <column class="w-full">
                <text class="{{ $section }}">Appearance — currently {{ $appearanceNow }}</text>
                <column class="{{ $card }}">
                    @foreach (['system' => 'Automatic (follow system)', 'light' => 'Light', 'dark' => 'Dark'] as $key => $title)
                        <pressable native:key="theme-{{ $key }}" class="w-full" @tap="setTheme('{{ $key }}')">
                            <row class="w-full items-center gap-3 px-4 py-3">
                                <icon name="{{ $key === 'system' ? 'circle.lefthalf.filled' : ($key === 'light' ? 'sun.max.fill' : 'moon.fill') }}" size="18" color="#8E8E93" />
                                <text class="{{ $label }}">{{ $title }}</text>
                                @if ($theme === $key)
                                    <icon name="checkmark" size="16" color="#0A7CFF" />
                                @endif
                            </row>
                        </pressable>
                        @if (! $loop->last)
                            <divider class="w-full ml-12" />
                        @endif
                    @endforeach
                </column>
                <text class="{{ $hint }} pt-2">
                    Light and Dark follow the device today; a forced override applies on next launch.
                </text>
            </column>

            {{-- About --}}
            <column class="w-full">
                <column class="{{ $card }}">
                    <row class="w-full items-center gap-3 px-4 py-3">
                        <text class="{{ $label }}">Server link</text>
                        <text class="text-sm {{ $apiLinked ? 'text-[#31D158]' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $apiLinked ? 'Connected' : 'Offline / local' }}</text>
                    </row>
                    <divider class="w-full ml-4" />
                    <row class="w-full items-center gap-3 px-4 py-3">
                        <text class="{{ $label }}">Version</text>
                        <text class="text-sm text-zinc-400 dark:text-zinc-500">SuperNative 1.0</text>
                    </row>
                </column>
            </column>

            <text class="text-xs text-zinc-400 dark:text-zinc-600 text-center px-4">Built with NativePHP Edge components.</text>
        </column>
    </scroll-view>
</column>
