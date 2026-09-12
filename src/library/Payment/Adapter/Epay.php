<?php

declare(strict_types=1);

/**
 * 易支付-支付宝 for FOSSBilling (VPS secondary development)
 *
 * Based on community adapter by xkatld:
 * https://github.com/xkatld/FOSSBilling-Patch
 * Adapted to current FOSSBilling Payment_Adapter / Doctrine APIs.
 *
 * @link https://github.com/xkatld/FOSSBilling-Patch
 */

use Box\Mod\Invoice\Entity\Invoice;
use Box\Mod\Invoice\Entity\Transaction;
use Pimple\Container;

class Payment_Adapter_Epay extends Payment_AdapterAbstract implements FOSSBilling\InjectionAwareInterface
{
    protected ?Container $di = null;

    public function setDi(Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        if (empty($this->config['apiurl'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Epay', ':missing' => 'apiurl'], 4001);
        }
        if (empty($this->config['pid'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Epay', ':missing' => 'pid'], 4001);
        }
        if (empty($this->config['key'])) {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Epay', ':missing' => 'key'], 4001);
        }
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => '易支付-支付宝（VPS / FOSSBilling-vps）。署名原作者 xkatld / FOSSBilling-Patch。',
            'logo' => [
                'logo' => 'alipay.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'apiurl' => [
                    'text', [
                        'label' => '易支付网关地址',
                    ],
                ],
                'pid' => [
                    'text', [
                        'label' => '商户ID (pid)',
                    ],
                ],
                'key' => [
                    'text', [
                        'label' => '商户密钥 (key)',
                        'secret' => true,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription): string
    {
        if ($subscription) {
            throw new Payment_Exception('Epay does not support subscriptions');
        }

        $invoiceModel = $this->di['em']->getRepository(Invoice::class)->find($invoice_id);
        if (!$invoiceModel instanceof Invoice) {
            throw new Payment_Exception('Invoice not found');
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceTotal = $invoiceService->getTotalWithTax($invoiceModel);

        $notifyUrl = (string) ($this->config['notify_url'] ?? '');
        $returnUrl = (string) ($this->config['thankyou_url'] ?? $this->config['return_url'] ?? $notifyUrl);
        if ($notifyUrl === '') {
            throw new Payment_Exception('IPN Notification URL for the payment gateway was not set', [], 6003);
        }

        $invoiceId = (int) $invoiceModel->getId();
        $hash = (string) $invoiceModel->getHash();
        $shortHash = substr(md5($hash), 0, 8);
        $outTradeNo = $invoiceId . '_' . $shortHash . '_' . time();

        $serie = (string) ($invoiceModel->getSerie() ?? '');
        $nr = (int) ($invoiceModel->getNr() ?? 0);
        $name = '订单 #' . $serie . sprintf('%05d', $nr);
        $money = $this->moneyFormat($invoiceTotal, $invoiceModel->getCurrency());

        $apiurl = rtrim((string) $this->config['apiurl'], '/');

        $param = [
            'pid' => strval($this->config['pid']),
            'type' => 'alipay',
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'out_trade_no' => $outTradeNo,
            'name' => $name,
            'money' => $money,
        ];
        $param['sign'] = $this->buildSign($param);
        $param['sign_type'] = 'MD5';

        $submitUrl = $apiurl . '/submit.php';

        return $this->generateAutoSubmitForm($submitUrl, $param);
    }

    public function getInvoiceId($data)
    {
        $outTradeNo = $data['get']['out_trade_no'] ?? '';
        if ($outTradeNo !== '') {
            $parts = explode('_', (string) $outTradeNo);
            if (count($parts) >= 2 && is_numeric($parts[0])) {
                return (int) $parts[0];
            }
        }

        return $data['get']['invoice_id'] ?? ($data['invoice_id'] ?? null);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id): void
    {
        $get = $data['get'] ?? [];

        $epayParams = [];
        foreach (['pid', 'trade_no', 'out_trade_no', 'type', 'name', 'money', 'trade_status', 'sign', 'sign_type'] as $key) {
            if (isset($get[$key]) && $get[$key] !== '') {
                $epayParams[$key] = $get[$key];
            }
        }

        if (!$this->verifySign($epayParams)) {
            throw new Payment_Exception('易支付签名验证失败');
        }

        $tx = $api_admin->invoice_transaction_get(['id' => $id]);

        if (empty($tx['invoice_id'])) {
            $invoiceId = $this->getInvoiceId($data);
            if ($invoiceId) {
                $api_admin->invoice_transaction_update(['id' => $id, 'invoice_id' => $invoiceId]);
                $tx['invoice_id'] = $invoiceId;
            }
        }

        $tradeStatus = (string) ($get['trade_status'] ?? '');
        $tradeNo = (string) ($get['trade_no'] ?? '');
        $money = (string) ($get['money'] ?? '0');

        $api_admin->invoice_transaction_update([
            'id' => $id,
            'txn_id' => $tradeNo,
            'txn_status' => $tradeStatus,
            'amount' => $money,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($tradeStatus !== 'TRADE_SUCCESS') {
            $api_admin->invoice_transaction_update([
                'id' => $id,
                'status' => Transaction::STATUS_RECEIVED,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->setOutput('success');

            return;
        }

        if (($tx['status'] ?? null) === Transaction::STATUS_PROCESSED) {
            $this->setOutput('success');

            return;
        }

        if (!$api_admin->invoice_transaction_claim_for_processing(['id' => $id])) {
            $this->setOutput('success');

            return;
        }

        $tx = $api_admin->invoice_transaction_get(['id' => $id]);
        $invoice = $api_admin->invoice_get(['id' => $tx['invoice_id']]);
        $clientId = $invoice['client']['id'];

        $invoiceDbModel = $this->di['em']->getRepository(Invoice::class)->find($tx['invoice_id']);
        if ($invoiceDbModel instanceof Invoice) {
            $invoiceService = $this->di['mod_service']('Invoice');
            $expected = $invoiceService->getTotalWithTax($invoiceDbModel);
            $invoiceService->validatePaymentAmount((float) $money, $expected);
        }

        $api_admin->client_balance_add_funds([
            'id' => $clientId,
            'amount' => $money,
            'description' => '易支付交易 ' . $tradeNo,
            'type' => 'Epay',
            'rel_id' => $tradeNo,
        ]);

        if (!empty($tx['invoice_id'])) {
            $api_admin->invoice_pay_with_credits(['id' => $tx['invoice_id']]);
        }

        $api_admin->invoice_transaction_update([
            'id' => $id,
            'error' => '',
            'error_code' => null,
            'status' => Transaction::STATUS_PROCESSED,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->setOutput('success');
    }

    private function generateAutoSubmitForm(string $url, array $data): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<form id="dopay" name="payment_form" action="' . $safeUrl . '" method="post">' . PHP_EOL;
        foreach ($data as $key => $value) {
            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= sprintf('<input type="hidden" name="%s" value="%s" />', $safeKey, $safeValue) . PHP_EOL;
        }
        $html .= '<input class="btn btn-primary" type="submit" value="正在跳转到支付页面..." id="payment_button"/>' . PHP_EOL;
        $html .= '</form>' . PHP_EOL;
        $html .= "<script>document.addEventListener('DOMContentLoaded', function() { var b=document.getElementById('payment_button'); if(b){b.style.display='none';} document.getElementById('dopay').submit(); });</script>";

        return $html;
    }

    private function buildSign(array $param): string
    {
        ksort($param);
        $signstr = '';
        foreach ($param as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '' && $v !== null) {
                $signstr .= $k . '=' . $v . '&';
            }
        }
        $signstr = substr($signstr, 0, -1);
        $signstr .= $this->config['key'];

        return md5($signstr);
    }

    private function verifySign(array $param): bool
    {
        if (empty($param['sign'])) {
            return false;
        }
        $sign = $this->buildSign($param);

        return hash_equals($sign, (string) $param['sign']);
    }
}
