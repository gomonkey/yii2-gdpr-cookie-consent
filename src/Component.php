<?php

namespace albertborsos\cookieconsent;

use albertborsos\cookieconsent\widgets\CookieWidget;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;

class Component extends \yii\base\Component
{

    const STATUS_DENIED = 'deny';
    const STATUS_DISMISSED = 'dismiss';
    const STATUS_ALLOWED = 'allow';

    const STATUSES = [
        self::STATUS_DENIED,
        self::STATUS_DISMISSED,
        self::STATUS_ALLOWED,
    ];

    const COMPLIANCE_TYPE_INFO = 'info';
    const COMPLIANCE_TYPE_OPT_IN = 'opt-in';
    const COMPLIANCE_TYPE_OPT_OUT = 'opt-out';

    const COMPLIANCE_TYPES = [
        self::COMPLIANCE_TYPE_INFO,
        self::COMPLIANCE_TYPE_OPT_IN,
        self::COMPLIANCE_TYPE_OPT_OUT,
    ];

    const CATEGORY_SESSION      = 'session';
    const CATEGORY_ADS          = 'ads';
    const CATEGORY_USAGE_HELPER = 'usagehelper';
    const CATEGORY_PERFORMANCE  = 'performance';
    const CATEGORY_BEHAVIOR     = 'behavior';

    const CATEGORIES = [
        self::CATEGORY_SESSION,
        self::CATEGORY_ADS,
        self::CATEGORY_USAGE_HELPER,
        self::CATEGORY_PERFORMANCE,
        self::CATEGORY_BEHAVIOR,
    ];

    const CATEGORIES_REQUIRED = [
        self::CATEGORY_SESSION,
        self::CATEGORY_USAGE_HELPER,
    ];

    const COOKIE_OPTION_PREFIX = 'cookieconsent_option_';

    /**
     * Suggested format in config:
     *
     * ```
     *  'extraCategories' => [
     *      'customCategory' => [
     *          'label' => 'Custom Category',
     *          'hint' => 'Description of the Custom category.',
     *      ],
     *  ],
     * ```
     *
     * @var array custom cookie categories
     */
    public $extraCategories = [];

    /**
     * Categories to hide from settings form.
     * `session` and `usagehelper` categories will be ignored from this list.
     *
     * ```
     * 'disabledCategories' => [
     *     \albertborsos\cookieconsent\Component::CATEGORY_BEHAVIOR,
     * ],
     * ```
     *
     * @var array
     */
    public $disabledCategories = [];

    /**
     * @var string compliance type
     */
    public $complianceType;

    /**
     * @var boolean calculated by the compliance type
     */
    private $_defaultCookieValue;

    /**
     * @var string value of `cookieconsent_status` cookie
     */
    private $_status;

    /**
     * @throws InvalidConfigException
     */
    public function init()
    {
        if (!in_array($this->complianceType, self::COMPLIANCE_TYPES)) {
            throw new InvalidArgumentException('Invalid value in "type" property!');
        }

        $this->setStatus();
        $this->calculateDefaultCookieValue();
        $this->normalizeExtraCategories();
        $this->normalizeDisabledCategories();
        parent::init();
    }

    /**
     * @param array $config
     * @throws \Exception
     */
    public function registerWidget($config = [])
    {
        CookieWidget::widget($config);
    }

    /**
     * @return array
     */
    public function getCategories()
    {
        $categories = ArrayHelper::merge(self::CATEGORIES, array_keys($this->extraCategories));

        return array_diff($categories, $this->disabledCategories);
    }

    /**
     * @param null $status
     */
    public function setStatus($status = null)
    {
        $this->_status = $status ?: ArrayHelper::getValue($_COOKIE, 'cookieconsent_status');
    }

    /**
     * @return mixed|null
     */
    public function getStatus()
    {
        if (empty($this->_status)) {
            $this->setStatus();
        }
        return $this->_status;
    }

    /**
     * This means user NEVER accepts cookies!
     *
     * This means user refuses cookies if compliance type is:
     *  - `opt-out`
     *
     * @return bool
     */
    protected function isStatusDenied()
    {
        return $this->getStatus() === self::STATUS_DENIED;
    }

    /**
     * This means user accepts cookies if compliance type is:
     *  - `opt-out`
     *  - `info`
     *
     * This means user refuses cookies if compliance type is:
     *  - `opt-in`
     *
     * @return bool
     */
    protected function isStatusDismissed()
    {
        return $this->getStatus() === self::STATUS_DISMISSED;
    }

    /**
     * This means user accepts cookies if compliance type is:
     *  - `opt-in`
     *
     * @return bool
     */
    protected function isStatusAllowed()
    {
        return $this->getStatus() === self::STATUS_ALLOWED;
    }

    /**
     * @return bool
     */
    public function isOptOut()
    {
        return $this->complianceType === self::COMPLIANCE_TYPE_OPT_OUT;
    }

    /**
     * @return bool
     */
    public function isOptIn()
    {
        return $this->complianceType === self::COMPLIANCE_TYPE_OPT_IN;
    }

    /**
     * @return bool
     */
    public function isInfo()
    {
        return $this->complianceType === self::COMPLIANCE_TYPE_INFO;
    }

    /**
     * @param null|string $category
     * @return bool
     */
    public function isAllowed($category = null)
    {
        if (!\Yii::$app instanceof \yii\web\Application) {
            return false;
        }

        if ($category) {
            if ($this->isRequiredToAllow($category)) {
                return true;
            }
            return ArrayHelper::getValue($_COOKIE, self::COOKIE_OPTION_PREFIX . $category, $this->getDefaultCookieValue());
        }

        // global status
        switch ($this->complianceType) {
            case self::COMPLIANCE_TYPE_INFO:
            case self::COMPLIANCE_TYPE_OPT_OUT:
                return $this->isStatusDismissed();
                break;
            case self::COMPLIANCE_TYPE_OPT_IN:
                return $this->isStatusAllowed();
                break;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isAnswered()
    {
        return $this->getStatus() !== null;
    }

    private function calculateDefaultCookieValue()
    {
        switch ($this->complianceType) {
            case self::COMPLIANCE_TYPE_INFO:
            case self::COMPLIANCE_TYPE_OPT_OUT:
                $this->_defaultCookieValue = $this->isAnswered() ? $this->isAllowed() : true;
                break;
            case self::COMPLIANCE_TYPE_OPT_IN:
                // while it is not allowed, it is false
                $this->_defaultCookieValue = $this->isAnswered() ? $this->isAllowed() : false;
                break;
        }
    }

    /**
     * @return bool
     */
    public function getDefaultCookieValue()
    {
        return $this->_defaultCookieValue;
    }

    private function normalizeExtraCategories()
    {
        foreach ($this->extraCategories as $id => $data) {
            if (!is_array($data) && is_int($id)) {
                unset($this->extraCategories[$id]);
                $id = $data;
                $data = [];
            }

            if (in_array($id, self::CATEGORIES)) {
                throw new InvalidConfigException('You cannot use "' . $id . '" default category in "extraCategories" property items.');
            }

            if (isset($data['id'])) {
                throw new InvalidConfigException('Do not set "id" for "extraCategories" property items.');
            }

            if (preg_match('/[^A-Za-z]+/', $id)) {
                throw new InvalidConfigException('Category names must contains only word characters.');
            }

            $this->extraCategories[$id] = [
                'id' => $id,
                'label' => ArrayHelper::getValue($data, 'label', Inflector::humanize($id)),
                'hint' => ArrayHelper::getValue($data, 'hint', Inflector::humanize($id)),
            ];
        }
    }

    private function normalizeDisabledCategories()
    {
        foreach (self::CATEGORIES_REQUIRED as $requiredCategory) {
            ArrayHelper::removeValue($this->disabledCategories, $requiredCategory);
        }
    }

    public function isRequiredToAllow($category)
    {
        return in_array($category, self::CATEGORIES_REQUIRED);
    }
}
