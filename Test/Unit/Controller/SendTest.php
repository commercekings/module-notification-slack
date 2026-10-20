<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Test\Unit\Controller;

use CommerceKings\NotificationSlack\Controller\Adminhtml\Test\Send;
use CommerceKings\NotificationSlack\Model\TestMessage;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;

/** The button's endpoint: POST only, its own ACL resource, the scope's website, and the outcome as JSON. */
class SendTest extends TestCase
{
    private array $websites = [];
    private array $data = [];

    private function execute(mixed $website): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($name, $default = null) => $name === 'website' ? $website : $default);
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $testMessage = $this->createStub(TestMessage::class);
        $testMessage->method('send')->willReturnCallback(function (?int $websiteId) {
            $this->websites[] = $websiteId;
            return [true, 'Posted to C0123ABCD.'];
        });
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data[] = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);
        (new Send($context, $testMessage, $jsonFactory))->execute();
    }

    public function testThePostedScopeIsTestedAndAnsweredAsJson(): void
    {
        foreach (['2', '', '0', 'x', null, ['1']] as $website) {
            $this->execute($website);
        }
        $this->assertSame([2, null, null, null, null, null], $this->websites);
        $this->assertSame(['success' => true, 'message' => 'Posted to C0123ABCD.'], $this->data[0]);
    }

    public function testItIsAPostWithItsOwnAclResource(): void
    {
        $this->assertTrue(is_subclass_of(Send::class, HttpPostActionInterface::class));
        $this->assertSame('CommerceKings_NotificationSlack::test_message', Send::ADMIN_RESOURCE);
        $acl = file_get_contents(__DIR__ . '/../../../etc/acl.xml');
        $this->assertStringContainsString('<resource id="CommerceKings_NotificationSlack::test_message"', $acl);
    }
}
