<column class="w-full h-full bg-white dark:bg-black">
    @if ($empty)
        <column class="flex-1 w-full items-center justify-center gap-2 p-10">
            <text class="text-base font-semibold text-zinc-900 dark:text-zinc-50">No one to message</text>
            <text class="text-sm text-zinc-500 dark:text-zinc-400 text-center">Invite some teammates and they'll show up here.</text>
        </column>
    @else
        <scroll-view class="flex-1 w-full">
            <column class="w-full py-2">
                <text class="text-xs font-semibold text-zinc-400 dark:text-zinc-500 uppercase px-4 py-2">Suggested</text>

                @foreach ($people as $person)
                    <pressable native:key="person-{{ $person['id'] }}" class="w-full" @tap="startWith({{ $person['id'] }})">
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
                                <text class="text-sm text-zinc-500 dark:text-zinc-400" max-lines="1">{{ $person['handle'] }}</text>
                            </column>

                            <icon name="chevron.right" size="14" color="#C7C7CC" />
                        </row>
                    </pressable>
                @endforeach
            </column>
        </scroll-view>
    @endif
</column>
