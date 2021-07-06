<?php

namespace Shetabit\Multipay\Drivers\Vandar;

use GuzzleHttp\Client;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Receipt;
use Shetabit\Multipay\RedirectionForm;
use Shetabit\Multipay\Request;

class Vandar extends Driver
{
    /**
     * Vandar Client.
     *
     * @var object
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Vandar constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
    }

    /**
     * Retrieve data from details using its name.
     *
     * @return string
     */
    private function extractDetails($name)
    {
        return $this->invoice->getDetail($name);
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function purchase()
    {
        $mobile = $this->extractDetails('mobile');
        $description = $this->extractDetails('description');
        $validCardNumber = $this->extractDetails('validCardNumber');

        $data = [
            'api_key' => $this->settings->merchantId,
            'amount' => ($this->invoice->getAmount() * 10), // convert rial to toman
            'callback_url' => $this->settings->callbackUrl,
            'mobile_number' => $mobile,
            'description' => $description,
            'factorNumber' => $this->invoice->getUuid(),
            'valid_card_number' => $validCardNumber
        ];

        $response = $this->client->post(
            $this->settings->apiPurchaseUrl,
            [
                "json" => $data,
                "http_errors" => false
            ]
        );
        $body = json_decode($response->getBody()->getContents());

        if ($body->status != 1) {
            // some error has happened
            throw new PurchaseFailedException(implode(PHP_EOL, $body->errors));
        }

        $this->invoice->transactionId($body->token);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return RedirectionForm
     */
    public function pay(): RedirectionForm
    {
        $payUrl = $this->settings->apiPaymentUrl . $this->invoice->getTransactionId();

        return $this->redirectWithForm($payUrl, [], 'GET');
    }

    /**
     * Verify payment
     *
     * @return ReceiptInterface
     *
     * @throws InvalidPaymentException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function verify(): ReceiptInterface
    {
        if (Request::input('payment_status') !== 'OK'){
            $this->notVerified('پرداخت با شکست مواجه شد');
        }

        $data = [
            'api_key' => $this->settings->merchantId,
            'token'  => $this->invoice->getTransactionId() ?? Request::input('token'),
        ];

        $response = $this->client->post(
            $this->settings->apiVerificationUrl,
            [
                "json" => $data,
                "http_errors" => false,
            ]
        );
        $body = json_decode($response->getBody()->getContents());

        if ($body->status !== 1) {
            $this->notVerified(implode(PHP_EOL, $body->errors));
        }

        $receipt = $this->createReceipt($body->transId);
        $receipt->detail([
            'amount' => $body->amount,
            'cardNumber' => $body->cardNumber,
            'cid' => $body->cid,
            'description' => $body->description,
            'factorNumber' => $body->factorNumber,
            'message' => $body->message,
            'mobile' => $body->mobile,
            'paymentDate' => $body->paymentDate,
            'realAmount' => $body->realAmount,
            'wage' => $body->wage,
        ]);
        return $receipt;
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    protected function createReceipt($referenceId)
    {
        return new Receipt('vandar', $referenceId);
    }

    /**
     * Trigger an exception
     *
     * @param $message
     * @throws InvalidPaymentException
     */
    private function notVerified($message)
    {
        if (empty($message)) {
            throw new InvalidPaymentException('خطای ناشناخته ای رخ داده است.');
        } else {
            throw new InvalidPaymentException($message);
        }
    }
}
