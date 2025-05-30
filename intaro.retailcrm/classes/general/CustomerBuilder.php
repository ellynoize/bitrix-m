<?php

/**
 * @category RetailCRM
 * @package  RetailCRM
 * @author   RetailCRM <integration@retailcrm.ru>
 * @license  MIT
 * @link     http://retailcrm.ru
 * @see      http://retailcrm.ru/docs
 */

/**
 * Class CustomerBuilder
 *
 * @category RetailCRM
 * @package RetailCRM
 */
class CustomerBuilder extends AbstractBuilder implements RetailcrmBuilderInterface
{
    /** @var Customer */
    protected $customer;

    /** @var CustomerAddress */
    protected $customerAddress;

    /** @var array $dataCrm customerHistory */
    protected $dataCrm;

    /** @var AddressBuilder */
    protected $addressBuilder;

    /** @var CUser */
    protected $user;

    /** @var bool $registerNewUser */
    protected $registerNewUser;

    /** @var int $registeredUserID */
    protected $registeredUserID;

    /**
     * CustomerBuilder constructor.
     */
    public function __construct()
    {
        $this->customer = new Customer();
        $this->customerAddress = new CustomerAddress();
        $this->addressBuilder = new AddressBuilder();
    }
    
    /**
     * @param Customer $customer
     * @return $this
     */
    public function setCustomer(Customer $customer)
    {
        $this->customer = $customer;
        
        return $this;
    }

    /**
     * @return Customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    /**
     * @param CustomerAddress $customerAddress
     * @return $this
     */
    public function setCustomerAddress($customerAddress): CustomerBuilder
    {
        $this->customerAddress = $customerAddress;
        return $this;
    }

    /**
     * @return CustomerAddress
     */
    public function getCustomerAddress()
    {
        return  $this->customerAddress;
    }

    /**
     * @param array $dataCrm
     * @return $this
     */
    public function setDataCrm($dataCrm)
    {
        $this->dataCrm = $dataCrm;
        
        return $this;
    }

    /**
     * @param array $user
     * @return $this
     */
    public function setUser($user)
    {
        $this->user = $user;
        
        return $this;
    }
    
    /**
     * @param int $registeredUserID
     * @return $this
     */
    public function setRegisteredUserID(int $registeredUserID)
    {
        $this->registeredUserID = $registeredUserID;
        
        return $this;
    }

    /**
     * @return int
     */
    public function getRegisteredUserID()
    {
        return $this->registeredUserID;
    }

    /**
     * @return bool
     */
    public function getRegisterNewUser()
    {
        return $this->registerNewUser;
    }

    public function build()
    {
        if (!empty($this->dataCrm['firstName'])) {
            $this->customer->setName($this->fromJSON($this->dataCrm['firstName']));
        }

        if (!empty($this->dataCrm['lastName'])) {
            $this->customer->setLastName($this->fromJSON($this->dataCrm['lastName']));
        }

        if (!empty($this->dataCrm['patronymic'])) {
            $this->customer->setSecondName($this->fromJSON($this->dataCrm['patronymic']));
        }

        if (isset($this->dataCrm['phones'])) {
            foreach ($this->dataCrm['phones'] as $phone) {
                if (is_array($this->user) && isset($phone['old_number']) && in_array($phone['old_number'], $this->user)) {
                    $key = array_search($phone['old_number'], $this->user);

                    if (isset($phone['number'])) {
                        $this->user[$key] = $phone['number'];
                    } else {
                        $this->user[$key] = '';
                    }
                }

                if (isset($phone['number'])) {
                    if (\Bitrix\Main\Config\Option::get('main', 'new_user_phone_required', 'N') === 'Y') {
                        $this->customer->setPhone($phone['number']);
                        $this->user['PHONE_NUMBER'] = $phone['number'];
                    }

                    if ((!isset($this->user['PERSONAL_PHONE']) || '' == $this->user['PERSONAL_PHONE'])
                        && $this->user['PERSONAL_MOBILE'] != $phone['number']
                    ) {
                        $this->customer->setPersonalPhone($phone['number']);
                        $this->user['PERSONAL_PHONE'] = $phone['number'];

                        continue;
                    }

                    if ((!isset($this->user['PERSONAL_MOBILE']) || '' == $this->user['PERSONAL_MOBILE'])
                        && $this->user['PERSONAL_PHONE'] != $phone['number']
                    ) {
                        $this->customer->setPersonalMobile($phone['number']);
                        $this->user['PERSONAL_MOBILE'] = $phone['number'];

                        continue;
                    }
                }
            }
        }

        if (!empty($this->dataCrm['address']['index'])) {
            $this->customer->setPersonalZip($this->fromJSON($this->dataCrm['address']['index']));
        }

        if (!empty($this->dataCrm['address']['city'])) {
            $this->customer->setPersonalCity($this->fromJSON($this->dataCrm['address']['city']));
        }

        if (!empty($this->dataCrm['birthday'])) {
            $this->customer->setPersonalBirthday($this->fromJSON(
                date("d.m.Y", strtotime($this->dataCrm['birthday']))
            ));
        }

        if (!empty($this->dataCrm['email'])) {
            $this->customer->setEmail($this->fromJSON($this->dataCrm['email']));
        }

        if (!empty($this->dataCrm['sex'])) {
            $this->customer->setPersonalGender($this->fromJSON($this->dataCrm['sex']));
        }

        if ((!isset($this->dataCrm['email']) || $this->dataCrm['email'] == '')
            && (!isset($this->dataCrm['externalId']))
        ) {
            $login = uniqid('user_' . time()) . '@example.com';
            $this->customer->setLogin($login)
                ->setEmail($login);
        }

        if (isset($this->dataCrm['address'])) {
            $this->buildAddress();
        }

        // клиент считается подписанным при значении равном null
        if (array_key_exists('emailMarketingUnsubscribedAt', $this->dataCrm)) {
            if (empty($this->dataCrm['emailMarketingUnsubscribedAt'])) {
                $this->customer->setSubscribe('Y');
            } else {
                $this->customer->setSubscribe('N');
            }
        }

        if (empty($this->dataCrm['externalId'])
            && (empty($this->dataCrm['firstName'])
            || empty($this->dataCrm['email']))
        ) {
            $api = new RetailCrm\ApiClient(RetailcrmConfigProvider::getApiUrl(), RetailcrmConfigProvider::getApiKey());
            $customerResponse = RCrmActions::apiMethod($api, 'customersGetById', __METHOD__, $this->dataCrm['id']);
    
            if ($customerResponse instanceof RetailCrm\Response\ApiResponse
                && $customerResponse->isSuccessful()
                && !empty($customerResponse['customer'])
            ) {
                $crmCustomer = $customerResponse['customer'];
                
                if (empty($this->dataCrm['email']) 
                    && !empty($crmCustomer['email'])
                ) {
                    $email = $crmCustomer['email'];

                    $this->customer->setEmail($this->fromJSON($email));
                    $this->customer->setLogin($email);
                }

                if (empty($this->dataCrm['firstName']) 
                    && !empty($crmCustomer['firstName'])
                ) {
                    $this->customer->setName($this->fromJSON($crmCustomer['firstName']));
                }
            } 
        }
    }

    public function buildPassword()
    {
        $userPassword = uniqid("R");
        $this->customer->setPassword($userPassword)
            ->setConfirmPassword($userPassword);

        return $this;
    }

    public function buildAddress()
    {
        if (isset($this->dataCrm['address'])) {
            $this->addressBuilder->setDataCrm($this->dataCrm['address'])->build();
            $this->customerAddress = $this->addressBuilder->getCustomerAddress();
        } else {
            $this->customerAddress = null;
        }
    }
    
    /**
     * @param string $login
     * @return $this
     */
    public function setLogin(string $login)
    {
        $this->customer->setLogin($login);

        return $this;
    }
    
    /**
     * @param string $email
     * @return $this
     */
    public function setEmail(string $email)
    {
        $this->customer->setEmail($email);

        return $this;
    }
    
    public function reset(): void
    {
        $this->customer = new Customer();
        $this->customerAddress = new CustomerAddress();
        $this->addressBuilder->reset();
    }
}
