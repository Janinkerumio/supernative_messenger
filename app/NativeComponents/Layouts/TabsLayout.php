<?php

namespace App\NativeComponents\Layouts;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * The shell that wraps every screen: a native NavigationStack + TabView on
 * iOS, NavHost + Scaffold on Android.
 *
 *  - The top bar text/actions come from each screen's navigationOptions().
 *  - The bottom tab bar is defined once here.
 *  - Detail screens (chat thread, new chat) set $hidesTabBar and their own
 *    ->back() nav bar, so they push cleanly over the tabs.
 */
class TabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function navBar(NativeComponent $screen): ?NavBar
    {
        // Screens describe their own bar via navigationOptions(); the
        // framework merges that in automatically. This is just the base.
        return NavBar::make()->displayMode('large');
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->activeColor('#0A7CFF')
            ->add(Tab::link('Chats', '/', icon: 'bubble.left.and.bubble.right.fill'))
            ->add(Tab::link('People', '/people', icon: 'person.2.fill'))
            ->add(Tab::link('Settings', '/settings', icon: 'gearshape.fill'));
    }
}
