<?php

namespace KJ\Payment\StripeBundle\Plugin;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use JMS\Payment\CoreBundle\Model\PaymentInstructionInterface;
use JMS\Payment\CoreBundle\Model\FinancialTransactionInterface;
use JMS\Payment\CoreBundle\Plugin\AbstractPlugin;
use JMS\Payment\CoreBundle\Plugin\PluginInterface;
use JMS\Payment\CoreBundle\Plugin\ErrorBuilder;
use JMS\Payment\CoreBundle\Plugin\Exception\FinancialException;
use JMS\Payment\CoreBundle\Plugin\RecurringPluginInterface;
use KJ\Payment\StripeBundle\Client\Client;
use KJ\Payment\StripeBundle\Client\Response;


/**
 * Stripe payment plugin
 *
 * @author kjchew <kjchew@gmail.com>
 */
class StripeCreditCardPlugin extends AbstractPlugin implements RecurringPluginInterface
{
    /**
     * Mapping of plan interval to stripe
     *
     * @var array
     */
    public static $intervalMapping = array(
        PaymentInstructionInterface::INTERVAL_WEEKLY => 'week',
        PaymentInstructionInterface::INTERVAL_MONTHLY => 'month',
        PaymentInstructionInterface::INTERVAL_ANNUALLY => 'year',
    );

    /**
     * @var \Symfony\Component\Validator\Validator\ValidatorInterface
     */
    protected $validator;

    /**
     * @var \KJ\Payment\StripeBundle\Client\Client
     */
    protected $client;

    /**
     * Constructor
     *
     * @param \Symfony\Component\Validator\Validator\ValidatorInterface $validator
     * @param \KJ\Payment\StripeBundle\Client\Client $client
     */
    public function __construct(ValidatorInterface $validator, Client $client)
    {
        $this->validator = $validator;
        $this->client = $client;
    }

    /**
     * @param string $paymentSystemName
     *
     * @return boolean
     */
    public function processes($paymentSystemName)
    {
        return 'stripe_credit_card' === $paymentSystemName;
    }

    /**
     * This method checks whether all required parameters exist in the given
     * PaymentInstruction, and whether they are syntactically correct.
     *
     * This method is meant to perform a fast parameter validation; no connection
     * to any payment back-end system should be made at this stage.
     *
     * In case, this method is not implemented. The PaymentInstruction will
     * be considered to be valid.
     *
     * @param PaymentInstructionInterface $instruction
     *
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\InvalidPaymentInstructionException if the the PaymentInstruction is not valid
     */
    public function checkPaymentInstruction(PaymentInstructionInterface $instruction)
    {
        // define form validators
        $constraints = new Assert\Collection(array(
            'name' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
            'number' => array(
                new Assert\NotBlank(array('message' => 'Required')),
                new Assert\Length(array('min' => 12, 'max' => 19, 'minMessage' => 'Invalid card number 1', 'maxMessage' => 'Invalid card number 2')),
                new Assert\Luhn(array('message' => 'Invalid card number')),
            ),
            'exp_month' => array(
                new Assert\NotBlank(array('message' => 'Required')),
                new Assert\Range(array('min' => 1, 'max' => 12, 'minMessage' => 'Invalid code value', 'maxMessage' => 'Invalid code value')),
            ),
            'exp_year' => array(
                new Assert\NotBlank(array('message' => 'Required')),
                new Assert\Range(array('min' => date('Y'), 'max' => date('Y', strtotime('+20 years')), 'minMessage' => 'Invalid date', 'maxMessage' => 'Invalid date')),
            ),
            'cvc' => array(
                new Assert\NotBlank(array('message' => 'Required')),
                new Assert\Length(array('min' => 3, 'max' => 4, 'minMessage' => 'Invalid code value', 'maxMessage' => 'Invalid code value')),
            ),
            'address_line1' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
            'address_line2' => array(),
            'address_city' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
            'address_state' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
            'address_country' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
            'address_zip' => array(
                new Assert\NotBlank(array('message' => 'Required')),
            ),
        ));

        // extract form values from extended data
        $dateToValidate = array();
        foreach ($constraints->fields as $name => $constraint) {
            $dateToValidate[$name] = $instruction->getExtendedData()->get($name);
        }

        // validate input data
        $errors = $this->validator->validate($dateToValidate, $constraints);

        // transform validator errors into payment exceptions
        $errorBuilder = new ErrorBuilder();
        foreach ($errors as $error) {
            // KLUDGE: remove [] around field name
            $field = substr($error->getPropertyPath(), 1, -1);

            $errorBuilder->addDataError('data_stripe_credit_card.' . $field, $error->getMessage());
        }

        if ($errorBuilder->hasErrors()) {
            throw $errorBuilder->getException();
        }
    }

    /**
     * This method executes an approve transaction.
     *
     * By an approval, funds are reserved but no actual money is transferred. A
     * subsequent deposit transaction must be performed to actually transfer the
     * money.
     *
     * A typical use case, would be Credit Card payments where funds are first
     * authorized.
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param boolean $retry Whether this is a retry transaction
     * @return void
     */
    public function approve(FinancialTransactionInterface $transaction, $retry)
    {
        $this->chargeCard($transaction, false);
    }

    /**
     * Charge a card
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param boolean $capture
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function chargeCard(FinancialTransactionInterface $transaction, $capture = true)
    {
        $data = $transaction->getExtendedData();

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();

        $cardDetails = array(
            'name' => $data->get('name'),
            'number' => $data->get('number'),
            'exp_month' => $data->get('exp_month'),
            'exp_year' => $data->get('exp_year'),
            'cvc' => $data->get('cvc'),
            'address_line1' => $data->get('address_line1'),
            'address_line2' => $data->get('address_line2'),
            'address_city' => $data->get('address_city'),
            'address_state' => $data->get('address_state'),
            'address_country' => $data->get('address_country'),
            'address_zip' => $data->get('address_zip'),
        );

        $response = $this->client->chargeCard(
            $transaction->getRequestedAmount(),
            $transaction->getPayment()->getPaymentInstruction()->getCurrency(),
            $opts['description'],
            $cardDetails,
            $capture
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        // complete the transaction
        $transaction->getExtendedData()->set('charge_id', $response->getResponse()->id);
        $transaction->setReferenceNumber($response->getResponse()->id);
        $transaction->setProcessedAmount($this->client->convertAmountFromStripeFormat($response->getResponse()->amount));
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * This method executes a deposit transaction without prior approval
     * (aka "sale", or "authorization with capture" transaction).
     *
     * A typical use case for this method is an electronic check payments
     * where authorization is not supported. It can also be used to deposit
     * money in only one transaction, and thus saving processing fees for
     * another transaction.
     *
     * @param \JMS\Payment\CoreBundle\Model\FinancialTransactionInterface $transaction
     * @param boolean $retry Whether this is a retry transaction
     * @return void
     */
    public function approveAndDeposit(FinancialTransactionInterface $transaction, $retry)
    {
        $this->chargeCard($transaction, true);
    }

    /**
     * This method executes a credit transaction against a Credit.
     *
     * @param FinancialTransactionInterface $transaction
     * @param $retry
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    public function credit(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();

        // refund transaction
        $response = $this->client->refund(
            $data->get('charge_id'),
            $transaction->getRequestedAmount()
        );
        $this->throwUnlessSuccessResponse($response, $transaction);

        // complete the transaction
        $transaction->setReferenceNumber($response->getResponse()->id);
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);

        $refund = array_pop($response->getResponse()->refunds);
        $transaction->setProcessedAmount($this->client->convertAmountFromStripeFormat($refund->getResponse()->amount));
    }

    /**
     * This method creates and assigns a subscription plan to a customer
     *
     * @param FinancialTransactionInterface $transaction
     * @param bool $retry
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    public function initializeRecurring(FinancialTransactionInterface $transaction, $retry)
    {
        $data = $transaction->getExtendedData();
        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();

        $cardToken = $this->findOrCreateCardId($transaction);
        $planId = $this->findOrCreatePlan($transaction);

        // create customer and assign card and plan
        $response = $this->client->createCustomerRequest($cardToken, $planId, $opts);
        $this->throwUnlessSuccessResponse($response, $transaction);

        // complete the transaction
        $transaction->getExtendedData()->set('customer_id', $response->getResponse()->id);
        $transaction->getExtendedData()->set('subscription_id', $response->getResponse()->subscription->id);
        $transaction->setReferenceNumber($response->getResponse()->subscription->id);
        $transaction->setProcessedAmount($transaction->getRequestedAmount());
        $transaction->setResponseCode(PluginInterface::RESPONSE_CODE_SUCCESS);
        $transaction->setReasonCode(PluginInterface::REASON_CODE_SUCCESS);
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return mixed
     */
    protected function findOrCreateCardId(FinancialTransactionInterface $transaction)
    {
        $data = $transaction->getExtendedData();
        if ($data->has('charge_id')) {
            return $data->get('charge_id');
        }
        $cardDetails = array(
            'name' => $data->get('name'),
            'number' => $data->get('number'),
            'exp_month' => $data->get('exp_month'),
            'exp_year' => $data->get('exp_year'),
            'cvc' => $data->get('cvc'),
            'address_line1' => $data->get('address_line1'),
            'address_line2' => $data->get('address_line2'),
            'address_city' => $data->get('address_city'),
            'address_state' => $data->get('address_state'),
            'address_country' => $data->get('address_country'),
            'address_zip' => $data->get('address_zip'),
        );

        $response = $this->client->createChargeToken($cardDetails);

        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('charge_id', $response->getResponse()->id);

        return $data->get('charge_id');
    }

    /**
     * @param FinancialTransactionInterface $transaction
     * @return mixed
     */
    protected function findOrCreatePlan(FinancialTransactionInterface $transaction)
    {
        $data = $transaction->getExtendedData();
        if ($data->has('plan_id')) {
            return $data->get('plan_id');
        }

        $opts = $data->has('checkout_params') ? $data->get('checkout_params') : array();

        $opts['id'] = array_key_exists('id', $opts) ? $opts['id'] : '';

        $response = $this->client->retrievePlan($opts['id']);

        if (!$response->isSuccess()) {
            $opts['amount'] = $this->client->convertAmountToStripeFormat($transaction->getRequestedAmount());
            $opts['currency'] = $transaction->getPayment()->getPaymentInstruction()->getCurrency();
            $opts['interval'] = $this->getIntervalForStripe($transaction->getPayment()->getPaymentInstruction()->getBillingInterval());
            $opts['interval_count'] = $transaction->getPayment()->getPaymentInstruction()->getBillingFrequency();

            $response = $this->client->createPlan($opts);
        }
        $this->throwUnlessSuccessResponse($response, $transaction);

        $data->set('plan_id', $response->getResponse()->id);

        return $data->get('plan_id');
    }


    /**
     * @param Response $response
     * @param FinancialTransactionInterface $transaction
     * @throws \JMS\Payment\CoreBundle\Plugin\Exception\FinancialException
     */
    protected function throwUnlessSuccessResponse(Response $response, FinancialTransactionInterface $transaction)
    {
        if ($response->isSuccess()) {
            return;
        }

        $transaction->setResponseCode($response->getErrorResponseCode());
        $transaction->setReasonCode($response->getErrorReasonCode());

        $ex = new FinancialException('Stripe-Response was not successful: '.$response->getErrorMessage());
        $ex->setFinancialTransaction($transaction);

        throw $ex;
    }

    /**
     * @param string $interval
     * @return string|bool
     */
    protected function getIntervalForStripe($interval)
    {
        if (array_key_exists($interval, self::$intervalMapping)) {
            return self::$intervalMapping[$interval];
        }
        return false;
    }

    /**
     * @param string $interval
     * @return bool
     */
    public function intervalSupported($interval)
    {
        return false !== $this->getIntervalForStripe($interval);
    }
}
