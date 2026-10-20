<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Controller\Adminhtml\Test;

use CommerceKings\NotificationSlack\Model\TestMessage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/** POST from the "Send test message" button (form key checked by the admin): {"success": bool, "message": string}. */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceKings_NotificationSlack::test_message';

    public function __construct(
        Context $context,
        private readonly TestMessage $testMessage,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $website = $this->getRequest()->getParam('website');
        $websiteId = is_string($website) && ctype_digit($website) && (int)$website > 0 ? (int)$website : null;
        [$success, $message] = $this->testMessage->send($websiteId);
        return $this->jsonFactory->create()->setData(['success' => $success, 'message' => $message]);
    }
}
