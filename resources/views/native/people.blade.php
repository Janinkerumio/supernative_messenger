<column class="w-full h-full bg-white dark:bg-black">
    <scroll-view class="flex-1 w-full">
        <column class="w-full py-2">

            @if ($activeCount > 0)
                <text class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase px-4 pt-2 pb-1">Active now — {{ $activeCount }}</text>
                <scroll-view class="w-full" horizontal>
                    <row class="items-start gap-4 px-4 py-3">
                        @foreach ($active as $person)
                            <pressable native:key="active-{{ $person['id'] }}" @tap="message({{ $person['id'] }})">
                                <column class="items-center gap-1.5 w-16">
                                    <column class="relative w-16 h-16">
                                        <column class="w-16 h-16 rounded-full items-center justify-center bg-[{{ $person['accent'] }}]">
                                            <text class="text-lg font-bold text-white">{{ $person['initials'] }}</text>
                                        </column>
                                        <column class="absolute bottom-0 right-0 w-4 h-4 rounded-full bg-[#31D158] border-2 border-white dark:border-black"></column>
                                    </column>
                                    <text class="text-xs text-zinc-600 dark:text-zinc-300" max-lines="1">{{ $person['name'] }}</text>
                                </column>
                            </pressable>
                        @endforeach
                    </row>
                </scroll-view>
                <divider class="w-full my-1" />
            @endif

            <text class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase px-4 pt-3 pb-1">All contacts</text>

            @foreach ($people as $person)
                <pressable native:key="contact-{{ $person['id'] }}" class="w-full" @tap="message({{ $person['id'] }})">
                    <row class="w-full items-center gap-3 px-4 py-2.5">
                        <column class="relative w-12 h-12">
                            <column class="w-12 h-12 rounded-full items-center justify-center bg-[{{ $person['accent'] }}]">
                                <text class="text-sm font-bold text-white">{{ $person['initials'] }}</text>
                            </column>
                            @if ($person['online'])
                                <column class="absolute bottom-0 right-0 w-3.5 h-3.5 rounded-full bg-[#31D158] border-2 border-white dark:border-black"></column>
                            @endif
                        </column>

                        <column class="flex-1 gap-0.5">
                            <text class="text-base font-semibold text-zinc-900 dark:text-zinc-50" max-lines="1">{{ $person['name'] }}</text>
                            <text class="text-sm text-zinc-500 dark:text-zinc-400" max-lines="1">{{ $person['presence'] }}</text>
                        </column>

                        <column class="w-9 h-9 rounded-full items-center justify-center bg-zinc-100 dark:bg-[#2C2C2E]">
                            <icon name="bubble.left.fill" size="16" color="#0A7CFF" />
                        </column>
                    </row>
                </pressable>
            @endforeach

        </column>
    </scroll-view>
</column>
