<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Wires Firebase Cloud Messaging into the generated Android project.
 *
 * NativePHP core only installs the Google Services Gradle plugin on behalf of
 * a *Firebase plugin* (see Native\Mobile\Plugins\LegacyFirebaseConfig). This
 * app has none, so without this step `google-services.json` is never copied,
 * the plugin is never applied, and PushNotification.GetToken returns nothing —
 * Android then shows no Notifications entry for the app.
 *
 * Idempotent. Run it after `native:install` and before `native:run` /
 * `native:build` (the manifest POST_NOTIFICATIONS permission is handled
 * separately by config('nativephp.permissions.push_notifications')).
 */
class PrepareAndroidPush extends Command
{
    protected $signature = 'messenger:android-push {--firebase-bom=33.7.0}';

    protected $description = 'Wire Firebase Cloud Messaging into the generated Android project';

    private const GS_PLUGIN = 'com.google.gms.google-services';

    public function handle(): int
    {
        $android = base_path('nativephp/android');

        if (! File::isDirectory($android)) {
            $this->error('nativephp/android not found — run `php artisan native:install` first.');

            return self::FAILURE;
        }

        $ok = $this->copyGoogleServices($android)
            && $this->patchRootGradle($android)
            && $this->patchAppGradle($android)
            && $this->ensureManifestPermission($android);

        if ($ok) {
            $this->newLine();
            $this->info('Android push wiring is in place. Rebuild with `php artisan native:run`.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function copyGoogleServices(string $android): bool
    {
        $source = base_path('resources/google-services.json');
        $target = $android.'/app/google-services.json';

        if (! File::exists($source)) {
            $this->error('resources/google-services.json is missing — download it from the Firebase console.');

            return false;
        }

        File::copy($source, $target);
        $this->line('  <info>✓</info> copied google-services.json → app/');

        return true;
    }

    private function patchRootGradle(string $android): bool
    {
        $path = $android.'/build.gradle.kts';
        $contents = File::get($path);

        if (str_contains($contents, self::GS_PLUGIN)) {
            $this->line('  <comment>·</comment> root build.gradle.kts already declares the Google Services plugin');

            return true;
        }

        $version = '4.4.2';
        $contents = preg_replace(
            '/(plugins\s*\{\s*\n)/',
            "$1    id(\"".self::GS_PLUGIN."\") version \"{$version}\" apply false\n",
            $contents,
            1,
        );

        File::put($path, $contents);
        $this->line('  <info>✓</info> declared the Google Services plugin in build.gradle.kts');

        return true;
    }

    private function patchAppGradle(string $android): bool
    {
        $path = $android.'/app/build.gradle.kts';
        $contents = File::get($path);

        // 1) apply the plugin
        if (! preg_match('/id\("'.preg_quote(self::GS_PLUGIN, '/').'"\)/', $contents)) {
            $contents = preg_replace(
                '/(plugins\s*\{\s*\n)/',
                "$1    id(\"".self::GS_PLUGIN."\")\n",
                $contents,
                1,
            );
            $this->line('  <info>✓</info> applied the Google Services plugin in app/build.gradle.kts');
        } else {
            $this->line('  <comment>·</comment> app/build.gradle.kts already applies the Google Services plugin');
        }

        // 2) firebase-messaging dependency, in a marker block we own
        if (! str_contains($contents, 'MESSENGER-FIREBASE-START')) {
            $bom = $this->option('firebase-bom');
            $block = <<<KOT

    // MESSENGER-FIREBASE-START (managed by `php artisan messenger:android-push`)
    implementation(platform("com.google.firebase:firebase-bom:{$bom}"))
    implementation("com.google.firebase:firebase-messaging")
    // MESSENGER-FIREBASE-END

KOT;
            $contents = preg_replace('/(dependencies\s*\{\s*\n)/', "$1{$block}", $contents, 1);
            $this->line('  <info>✓</info> added firebase-bom + firebase-messaging');
        } else {
            $this->line('  <comment>·</comment> firebase-messaging dependency already present');
        }

        File::put($path, $contents);

        return true;
    }

    private function ensureManifestPermission(string $android): bool
    {
        $path = $android.'/app/src/main/AndroidManifest.xml';
        $contents = File::get($path);

        if (str_contains($contents, 'android.permission.POST_NOTIFICATIONS')) {
            $this->line('  <comment>·</comment> POST_NOTIFICATIONS already in the manifest');

            return true;
        }

        $contents = preg_replace(
            '/(<uses-permission android:name="android\.permission\.INTERNET" \/>\s*\n)/',
            "$1    <uses-permission android:name=\"android.permission.POST_NOTIFICATIONS\" />\n",
            $contents,
            1,
        );

        File::put($path, $contents);
        $this->line('  <info>✓</info> added POST_NOTIFICATIONS to the manifest');

        return true;
    }
}
