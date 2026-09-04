<column class="w-full h-full bg-white dark:bg-black">
    @if ($empty)
        <column class="flex-1 w-full items-center justify-center gap-2 p-10">
            <column class="w-16 h-16 rounded-full items-center justify-center bg-[#0A7CFF]">
                <icon name="bubble.left.and.bubble.right.fill" size="30" color="#FFFFFF" />
            </column>
            <text class="text-lg font-bold text-zinc-900 dark:text-zinc-50">No chats yet</text>
            <text class="text-sm text-zinc-500 dark:text-zinc-400 text-center">Start a conversation with the compose button up top.</text>
        </column>
    @else
        <scroll-view class="flex-1 w-full">
            <column class="w-full py-1">
                @foreach ($rows as $row)
                    <pressable native:key="convo-{{ $row['id'] }}" class="w-full" @tap="open({{ $row['id'] }})">
                        <row class="w-full items-center gap-3 px-4 py-2.5">
                            <column class="relative w-14 h-14">
                                <column class="w-14 h-14 rounded-full items-center justify-center bg-[{{ $row['accent'] }}]">
                                    <text class="text-base font-bold text-white">{{ $row['initials'] }}</text>
                                </column>
                                @if ($row['online'])
                                    <column class="absolute bottom-0 right-0 w-4 h-4 rounded-full bg-[#31D158] border-2 border-white dark:border-black"></column>
                                @endif
                            </column>

                            <column class="flex-1 gap-0.5">
                                <text class="text-base font-semibold text-zinc-900 dark:text-zinc-50" max-lines="1">{{ $row['title'] }}</text>
                                <text class="text-sm text-zinc-500 dark:text-zinc-400" max-lines="1">{{ $row['preview'] }}</text>
                            </column>

                            <column class="items-end gap-1">
                                <text class="text-xs text-zinc-400 dark:text-zinc-500">{{ $row['time'] }}</text>
                                @if ($row['last_from_me'])
                                    <icon name="checkmark" size="12" color="#B0B0B8" />
                                @else
                                    <column class="w-2.5 h-2.5 rounded-full bg-[#0A7CFF]"></column>
                                @endif
                            </column>
                        </row>
                    </pressable>
                @endforeach
            </column>
        </scroll-view>
    @endif
</column>
