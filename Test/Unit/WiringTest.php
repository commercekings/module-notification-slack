<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The module plugs into Notification through its stable extension points only: every Notification class it names - in
 * PHP and in di.xml - is @api. And it stays light: one provider and one normalizer for "slack", lazy client and
 * settings, nothing for the storefront (no frontend code, plugins, observers or layout).
 */
class WiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function di(): \SimpleXMLElement
    {
        return simplexml_load_file(self::ROOT . '/etc/di.xml');
    }

    /** @return array<string, string> item name => value of one di.xml array argument */
    private static function items(string $type, string $argument): array
    {
        $items = [];
        foreach (self::di()->xpath(sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]/item', $type, $argument)) as $item) {
            $items[(string)$item['name']] = trim((string)$item);
        }
        return $items;
    }

    public function testOneProviderAndOneNormalizerForTheSlackChannel(): void
    {
        $this->assertSame(['slack' => 'CommerceKings\NotificationSlack\Model\Provider\SlackProvider'],
            self::items('CommerceKings\Notification\Model\ProviderPool', 'providers'));
        $this->assertSame(['slack' => 'CommerceKings\NotificationSlack\Model\ChannelIdNormalizer'],
            self::items('CommerceKings\Notification\Model\RecipientNormalizerPool', 'normalizers'));
        $this->assertSame(['commercekings_notification/slack/bot_token' => '1'],
            self::items('Magento\Config\Model\Config\TypePool', 'sensitive'));
    }

    public function testTheClientAndSettingsLoadOnlyWhenAMessageIsPosted(): void
    {
        $arguments = [];
        foreach (self::di()->xpath('//type[@name="CommerceKings\NotificationSlack\Model\Provider\SlackProvider"]/arguments/argument') as $argument) {
            $arguments[(string)$argument['name']] = trim((string)$argument);
        }
        $this->assertSame([
            'client' => 'CommerceKings\NotificationSlack\Model\Http\SlackClient\Proxy',
            'config' => 'CommerceKings\NotificationSlack\Model\Config\Proxy',
        ], $arguments);
    }

    public function testNothingRunsOnStorefrontRequests(): void
    {
        foreach (['etc/frontend', 'etc/webapi_rest', 'etc/graphql', 'view/frontend', 'Observer', 'Plugin',
            'etc/events.xml', 'etc/crontab.xml', 'etc/webapi.xml'] as $path) {
            $this->assertFileDoesNotExist(self::ROOT . '/' . $path);
        }
        $this->assertSame([], self::di()->xpath('//plugin'));
        $this->assertSame(['acl.xml', 'adminhtml', 'config.xml', 'di.xml', 'module.xml'],
            array_values(array_diff(scandir(self::ROOT . '/etc'), ['.', '..'])));
    }

    public function testTheAdminRouteServesTheButton(): void
    {
        $route = simplexml_load_file(self::ROOT . '/etc/adminhtml/routes.xml')->xpath('//router[@id="admin"]/route')[0];
        $this->assertSame('ck_notification_slack', (string)$route['frontName']);
        $system = file_get_contents(self::ROOT . '/etc/adminhtml/system.xml');
        $this->assertStringContainsString('CommerceKings\NotificationSlack\Block\Adminhtml\System\Config\TestButton', $system);
        $this->assertStringContainsString('<backend_model>Magento\Config\Model\Config\Backend\Encrypted</backend_model>', $system);
        $this->assertStringNotContainsString('api_base_url', $system, 'no admin field for the API URL');
    }

    /** @return string[] Notification classes this module names */
    private static function foreignClasses(): array
    {
        $sources = [];
        foreach (['Model', 'Controller', 'Block', 'Exception', 'etc'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir));
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/\.(php|xml)$/', $file->getFilename())) {
                    $sources[] = file_get_contents($file->getPathname());
                }
            }
        }
        preg_match_all('/CommerceKings\\\\Notification\\\\[A-Za-z\\\\]+/', implode("\n", $sources), $matches);
        return array_values(array_unique($matches[0]));
    }

    public function testEveryClassOfNotificationItUsesIsApi(): void
    {
        $classes = self::foreignClasses();
        $this->assertContains('CommerceKings\Notification\Api\ProviderInterface', $classes);
        foreach ($classes as $class) {
            $this->assertTrue(class_exists($class) || interface_exists($class), $class . ' exists');
            $doc = (string)(new \ReflectionClass($class))->getDocComment();
            $this->assertStringContainsString('@api', $doc, $class . ' is a stable extension point');
        }
    }
}
