<column class="w-full h-full bg-white dark:bg-black">
    <scroll-view class="flex-1 w-full">
        <column class="w-full items-center px-8 pt-24 pb-10 gap-7">

            <column class="items-center gap-3">
                <column class="w-20 h-20 rounded-3xl items-center justify-center bg-[#0A7CFF]">
                    <icon name="bubble.left.and.bubble.right.fill" size="34" color="#FFFFFF" />
                </column>
                <text class="text-2xl font-bold text-zinc-900 dark:text-zinc-50 text-center">Welcome to SuperNative</text>
                <text class="text-sm text-zinc-500 dark:text-zinc-400 text-center">
                    Choose a name and username so people can find and message you.
                </text>
            </column>

            <column class="w-full gap-3">
                <outlined-text-input
                    label="Your name"
                    native:model.live="name"
                    class="w-full" />

                <outlined-text-input
                    label="Username"
                    prefix="@"
                    native:model.live="username"
                    keyboard="text"
                    class="w-full" />

                @if ($error)
                    <row class="w-full items-center gap-1.5 px-1">
                        <icon name="exclamationmark.circle.fill" size="13" color="#E5484D" />
                        <text class="text-xs text-[#E5484D]">{{ $error }}</text>
                    </row>
                @endif
            </column>

            <pressable class="w-full" @tap="start">
                <column class="w-full rounded-2xl items-center justify-center py-3.5 {{ $canSubmit ? 'bg-[#0A7CFF]' : 'bg-zinc-200 dark:bg-zinc-700' }}">
                    <text class="text-base font-semibold {{ $canSubmit ? 'text-white' : 'text-zinc-400 dark:text-zinc-500' }}">Get started</text>
                </column>
            </pressable>

            <text class="text-xs text-zinc-400 dark:text-zinc-600 text-center px-2">
                No password needed — this device becomes your account.
            </text>
        </column>
    </scroll-view>
</column>
