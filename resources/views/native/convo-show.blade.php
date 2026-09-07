<column class="w-full h-full bg-white dark:bg-black">
    {{-- scroll-anchor=bottom: opens at the newest message and follows new
         content while the user is near the bottom (chat behaviour). --}}
    <scroll-view class="flex-1 w-full" scroll-anchor="bottom">
        <column class="w-full px-3 py-4 gap-1">
            {{-- Conversation intro --}}
            <column class="w-full items-center gap-2 py-6">
                <column class="w-20 h-20 rounded-full items-center justify-center bg-[{{ $accent }}]">
                    <text class="text-2xl font-bold text-white">{{ $initials }}</text>
                </column>
                <text class="text-lg font-bold text-zinc-900 dark:text-zinc-50">{{ $title }}</text>
                @if ($presence)
                    <text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $presence }}</text>
                @endif
            </column>

            @foreach ($rows as $row)
                <column native:key="msg-{{ $row['id'] }}" class="w-full {{ $row['grouped'] ? 'mt-0.5' : 'mt-3' }}">
                    @if ($row['mine'])
                        <column class="w-full items-end gap-0.5">
                            <row class="w-full justify-end">
                                <column class="max-w-[300] rounded-2xl bg-[#0A7CFF] px-4 py-2">
                                    <text class="text-base text-white">{{ $row['body'] }}</text>
                                </column>
                            </row>
                            @if ($row['seen'])
                                <text class="text-xs text-zinc-400 dark:text-zinc-500 pr-1">Seen</text>
                            @endif
                        </column>
                    @else
                        <row class="w-full items-end gap-2 justify-start">
                            <column class="w-7 h-7 rounded-full items-center justify-center bg-[{{ $row['accent'] }}] {{ $row['grouped'] ? 'opacity-0' : '' }}">
                                <text class="text-xs font-bold text-white">{{ \Illuminate\Support\Str::substr($row['initials'], 0, 1) }}</text>
                            </column>
                            <column class="max-w-[300] gap-0.5">
                                @if ($row['sender'] && ! $row['grouped'])
                                    <text class="text-xs text-zinc-400 dark:text-zinc-500 pl-1">{{ $row['sender'] }}</text>
                                @endif
                                <column class="rounded-2xl bg-zinc-100 dark:bg-[#2C2C2E] px-4 py-2">
                                    <text class="text-base text-zinc-900 dark:text-zinc-50">{{ $row['body'] }}</text>
                                </column>
                            </column>
                        </row>
                    @endif
                </column>
            @endforeach
        </column>
    </scroll-view>

    @if ($sendFailed)
        <row class="w-full items-center gap-2 px-4 py-2 bg-[#FDECEC] dark:bg-[#3A1B1B]">
            <icon name="wifi.slash" size="14" color="#C0392B" />
            <text class="flex-1 text-xs text-[#C0392B]">Couldn’t reach the server. Your message is still here — try again.</text>
        </row>
    @endif

    {{-- Composer — hoisted above the keyboard by the Edge runtime --}}
    <bottom-bar class="w-full">
        <row class="w-full items-end gap-2 px-3 py-2 bg-white dark:bg-black border-t border-zinc-200 dark:border-zinc-800">
            <column class="flex-1 rounded-2xl bg-zinc-100 dark:bg-[#1C1C1E] px-3 py-1.5">
                <bare-text-input
                    native:model.live="draft"
                    placeholder="Message"
                    multiline
                    max-lines="5"
                    keep-focus-on-submit
                    @submit="send"
                    class="w-full" />
            </column>

            <pressable class="w-9 h-9 rounded-full items-center justify-center {{ $canSend ? 'bg-[#0A7CFF]' : 'bg-zinc-200 dark:bg-zinc-700' }}" @tap="send">
                <icon name="paperplane.fill" size="17" color="{{ $canSend ? '#FFFFFF' : '#AEAEB6' }}" />
            </pressable>
        </row>
    </bottom-bar>
</column>
