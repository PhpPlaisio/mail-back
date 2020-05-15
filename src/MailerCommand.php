<?php
declare(strict_types=1);

namespace Plaisio\Mail\Command;

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Plaisio\C;
use Plaisio\CompanyResolver\UniCompanyResolver;
use Plaisio\PlaisioKernel;
use Psr\Log\LoggerInterface;
use SetBased\Exception\FallenException;
use SetBased\Exception\RuntimeException;
use Symfony\Component\Console\Command\Command;

/**
 * Abstract command for sending mail messages.
 */
abstract class MailerCommand extends Command
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The maximum number of unsent mails processed per batch.
   *
   * @var int
   */
  static protected $batchSize = 100;

  /**
   * The basename of the lock file.
   *
   * @var string
   */
  protected static $lockFilename = 'mailer.lock';

  /**
   * If true this command will terminate.
   *
   * @var bool
   */
  static protected $terminate = false;

  /**
   * The logger.
   *
   * @var LoggerInterface
   */
  protected $logger;

  /**
   * The kernel of PhpPlaisio.
   *
   * @var PlaisioKernel
   */
  protected $nub;

  /**
   * Array with domains for which we are authorized to send email.
   *
   * @var array
   */
  private $domains;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the list of domains for which we are authorized to send emails.
   */
  public function getAuthorizedDomains(): void
  {
    $domains = $this->nub->DL->abcMailBackGetAuthorizedDomains();

    foreach ($domains as $domain)
    {
      $this->domains[mb_strtolower($domain['atd_domain_name'])] = true;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the path to the lock file.
   *
   * @return string
   *
   * @api
   * @since 1.0.0
   */
  public function lockFilePath(): string
  {
    return $this->nub->dirs->lockDir().'/'.static::$lockFilename;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * This mailer sends mail messages for all companies in the databases. Changes the company to the company of the
   * mail message currently being send.
   *
   * @param int $cmpId The ID of the new company.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  protected function changeCompany(int $cmpId): void
  {
    if ($this->nub->company->cmpId!=$cmpId)
    {
      $this->nub->company = new UniCompanyResolver($cmpId);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Connects to the MySQL instance.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function connect(): void;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates the lock file.
   */
  protected function createLockFile(): void
  {
    file_put_contents($this->lockFilePath(), getmypid());
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Creates and initializes a PHPMailer object.
   *
   * This function might reuse an exiting PHPMailer object (with SMTPKeepAlive and clearing all addresses, headers,
   * attachments).
   *
   * @return PHPMailer
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function createMailer(): PHPMailer;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Disconnects from MySQL instance.
   *
   * @api
   * @since 1.0.0
   */
  protected function disconnect(): void
  {
    $this->nub->DL->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes the PID file.
   */
  protected function removeLockFile(): void
  {
    unlink($this->lockFilePath());
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sends a batch of mail messages.
   */
  protected function sendBatch(): void
  {
    $this->connect();
    $this->nub->DL->begin();

    $this->getAuthorizedDomains();

    do
    {
      $messages = $this->nub->DL->abcMailBackGetUnsentMessages(static::$batchSize);
      foreach ($messages as $message)
      {
        $this->sendMail($message);
      }
    } while (count($messages)==static::$batchSize && !self::$terminate);

    $this->disconnect();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the message body.
   *
   * @param PHPMailer $mailer  The PHPMailer object.
   * @param array     $message The details of the mail message.
   *
   * @api
   * @since 1.0.0
   */
  protected function setBody(PHPMailer $mailer, array $message): void
  {
    $blob = $this->nub->blob->getBlob($message['blb_id_body']);

    preg_match('/^([^;]*);\s*charset=(.*)$/', $blob['blb_mime_type'], $matches);
    if (sizeof($matches)!=3)
    {
      throw new RuntimeException("Invalid mime type '%s'", $blob['blb_mime_type']);
    }
    $type    = trim($matches[1]);
    $charset = trim($matches[2]);

    $mailer->isHTML(($type=='text/html'));
    $mailer->CharSet = $charset;
    $mailer->Body    = $blob['blb_data'];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the transmitter of this mail message when the transmitter's address is from an unauthorized domain.
   *
   * @param PHPMailer $mailer  The PHPMailer object.
   * @param array     $message The details of the mail message.
   *
   * @return void
   *
   * @api
   * @since 1.0.0
   */
  abstract protected function setUnauthorizedFrom(PHPMailer $mailer, array $message): void;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Adds all headers of a mail message to a PHPMailer object.
   *
   * @param PHPMailer $mailer  The PHPMailer object.
   * @param array     $message The details of the mail message.
   *
   * @throws Exception
   */
  private function addHeaders(PHPMailer $mailer, array $message): void
  {
    $headers = $this->nub->DL->abcMailBackMessageGetHeaders($message['cmp_id'], $message['elm_id']);

    $replyTo = false;
    foreach ($headers as $header)
    {
      switch ($header['ehd_id'])
      {
        case C::EHD_ID_ATTACHMENT:
          $blob = $this->nub->blob->getBlob($header['blb_id']);
          $mailer->addStringAttachment($blob['blb_data'], $blob['blb_filename']);
          break;

        case C::EHD_ID_BCC:
          $mailer->addBCC($header['emh_address'], $header['emh_name']);
          break;

        case C::EHD_ID_CC:
          $mailer->addCC($header['emh_address'], $header['emh_name']);
          break;

        case C::EHD_ID_CONFIRM_READING_TO:
          $mailer->ConfirmReadingTo = $header['emh_address'];
          break;

        case C::EHD_ID_CUSTOM_HEADER:
          $mailer->addCustomHeader($header['emh_value']);
          break;

        case C::EHD_ID_MESSAGE_ID:
          $mailer->MessageID = $header['emh_value'];
          break;

        case C::EHD_ID_REPLY_TO:
          $mailer->addReplyTo($header['emh_address'], $header['emh_name']);
          $replyTo = true;
          break;

        case C::EHD_ID_SENDER:
          $mailer->Sender = $header['emh_address'];
          break;

        case C::EHD_ID_TO:
          $mailer->addAddress($header['emh_address'], $header['emh_name']);
          break;

        default:
          throw new FallenException('ehd_id', $header['ehd_id']);
      }

      // Implicitly add ReplyTo header if not set explicitly.
      if (!$replyTo)
      {
        $mailer->addReplyTo($message['elm_address'], $message['elm_name']);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sends actually the email message.
   *
   * @param array $message The details of the mail message.
   */
  private function sendMail(array $message): void
  {
    try
    {
      $this->logger->notice(sprintf('Sending message elm_id=%d', $message['elm_id']));

      $this->changeCompany($message['cmp_id']);

      $this->nub->DL->abcMailBackMessageMarkAsPickedUp($message['cmp_id'], $message['elm_id']);
      $this->nub->DL->commit();

      if ($message['elm_number_from']!=1)
      {
        throw new RuntimeException('PHPMailer does not support multiple from addresses');
      }

      $mailer          = $this->createMailer();
      $mailer->Subject = $message['elm_subject'];

      $this->setBody($mailer, $message);
      $this->setFrom($mailer, $message);
      $this->addHeaders($mailer, $message);

      $success = $mailer->send();
      if ($success)
      {
        $this->nub->DL->abcMailBackMessageMarkAsSent($message['cmp_id'], $message['elm_id']);
        $this->nub->DL->commit();
      }
      else
      {
        $this->logger->error(sprintf('Sending message elm_id=%d failed: %s', $message['elm_id'], $mailer->ErrorInfo));
      }
    }
    catch (\Exception $exception)
    {
      $this->logger->critical($exception);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Sets the transmitter of this mail message.
   *
   * @param PHPMailer $mailer  The PHPMailer object.
   * @param array     $message The details of the mail message.
   */
  private function setFrom(PHPMailer $mailer, array $message): void
  {
    $domain = mb_strtolower(substr($message['elm_address'], strpos($message['elm_address'], '@') + 1));
    if (isset($this->domains[$domain]))
    {
      // We are authorized to send mail messages from this domain.
      $mailer->From     = $message['elm_address'];
      $mailer->FromName = $message['elm_name'];
    }
    else
    {
      // We are not authorized to send mail messages from this domain.
      $this->setUnauthorizedFrom($mailer, $message);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
