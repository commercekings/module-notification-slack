<?php
declare(strict_types=1);

namespace CommerceKings\NotificationSlack\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** The "Send test message" button: posts to the saved test channel of the scope shown (Default Config or a website). */
class TestButton extends Field
{
    /** @var string */
    protected $_template = 'CommerceKings_NotificationSlack::system/config/test-button.phtml';

    public function render(AbstractElement $element)
    {
        // an action, not a value: no "Use Default" checkbox and no scope label
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('html_id', $element->getHtmlId());
        return $this->_toHtml();
    }

    /** @return array<string, string> the button's x-magento-init configuration */
    public function getButtonConfig(): array
    {
        $website = $this->getRequest()->getParam('website');
        return [
            'url' => $this->getUrl('ck_notification_slack/test/send'),
            'website' => is_string($website) && ctype_digit($website) ? $website : '',
            'resultId' => $this->getData('html_id') . '_result',
            'sending' => (string)__('Sending…'),
            'failed' => (string)__('The request failed; reload the page and try again.'),
        ];
    }
}
