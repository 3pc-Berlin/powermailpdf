<?php

namespace Undkonsorten\Powermailpdf;


use In2code\Powermail\Controller\FormController;
use In2code\Powermail\Domain\Model\Answer;
use In2code\Powermail\Domain\Model\Field;
use In2code\Powermail\Domain\Model\Mail;
use TYPO3\CMS\Core\Error\Exception;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * PDF handling. Implements a signal slot createActionBeforeRenderView for Powermail.
 *
 */
class Pdf
{
    /** @var ResourceFactory */
    protected $resourceFactory;

    /** @var array */
    protected $settings;

    /** @var string */
    protected $additionalNamePart = '';

    /** @var string */
    protected $fileName = '';

    /** @var File|null */
    protected $downloadFile = null;

    public function __construct(ResourceFactory $resourceFactory)
    {
        $this->resourceFactory = $resourceFactory;
        $this->settings = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_powermailpdf.']['settings.'];
    }

    /**
     * @param Mail $mail
     * @return File
     * @throws Exception
     */
    protected function generatePdf(Mail $mail)
    {
        //Normal Fields
        $fieldMap = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_powermailpdf.']['settings.']['fieldMap.'];

        $answers = $mail->getAnswers();
        $fdfDataStrings = array();

        foreach ($fieldMap as $key => $value) {
            foreach ($answers as $answer) {
                if ($value == $answer->getField()->getMarker()) {
                    $fdfDataStrings[$key] = $answer->getValue();
                }
            }
        }

        $pdfOriginal = GeneralUtility::getFileAbsFileName($GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_powermailpdf.']['settings.']['sourceFile']);

        if (!empty($pdfOriginal)) {
            $info = pathinfo($pdfOriginal);
            $pdfFilename = basename($pdfOriginal, '.' . $info['extension']) . '_';
            $pdfTempFile = GeneralUtility::tempnam($pdfFilename, '.pdf');
            $pdf = new \FPDM($pdfOriginal);
            $pdf->useCheckboxParser = true; // Checkbox parsing is ignored (default FPDM behaviour) unless enabled with this setting https://github.com/codeshell/fpdm#checkboxes
            $pdf->Load($fdfDataStrings, true); // second parameter: false if field values are in ISO-8859-1, true if UTF-8
            $pdf->Merge();
            $pdf->Output("F", GeneralUtility::getFileAbsFileName($pdfTempFile));

        } else {
            throw new Exception("No pdf file is set in Typoscript. Please set tx_powermailpdf.settings.sourceFile if you want to use the filling feature.", 1417432239);
        }

        return $folder->addFile($pdfTempFile);

    }

    /**
     * @param File $file
     * @param $label
     * @return mixed
     */
    protected function render(File $file, $label)
    {

        $settings = $GLOBALS['TSFE']->tmpl->setup['plugin.']['tx_powermailpdf.']['settings.'];
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $standaloneView = $objectManager->get(StandaloneView::class);
        $templatePath = GeneralUtility::getFileAbsFileName($settings['template']);
        $standaloneView->setFormat('html');
        $standaloneView->setTemplatePathAndFilename($templatePath);
        $standaloneView->assignMultiple([
            'link' => $file->getPublicUrl(),
            'label' => $label
        ]);

        return $standaloneView->render();
    }

    /**
     * Signal slot createActionBeforeRenderView
     *
     * @param Mail $mail
     * @param \string $hash
     * @param \In2code\Powermail\Controller\FormController
     */
    public function createActionBeforeRenderView(Mail $mail, string $hash = '', $formController = null): void
    {

        if ($this->settings['enablePowermailPdf']) {
            if ($this->settings['target.']['additionalNamePart']) $this->additionalNamePart = iconv("UTF-8", "ASCII//TRANSLIT", $mail->getAnswersByFieldMarker()[$this->settings['target.']['additionalNamePart']]->getValue());
            if ($this->settings['sourceFile']) {
                if (!file_exists(GeneralUtility::getFileAbsFileName($this->settings['sourceFile']))) {
                    throw new \Exception("The file does not exist: " . $this->settings['sourceFile'] . " Please set correct path in plugin.tx_powermailpdf.settings.sourceFile", 1417520887);
                }
            }

            if ($this->settings['fillPdf']) {
                $powermailPdfFile = $this->generatePdf($mail);

            } else {
                $powermailPdfFile = null;
            }

            if ($this->settings['showDownloadLink'] && $this->settings['target.']['pdf'] && !empty($this->downloadFile)) {
                $queryParameterArray = ['eID' => 'dumpFile', 't' => 'f'];
                $queryParameterArray['f'] = $this->downloadFile->getUid();
                $queryParameterArray['token'] = GeneralUtility::hmac(implode('|', $queryParameterArray), 'resourceStorageDumpFile');
                $publicUrl = GeneralUtility::locationHeaderUrl(PathUtility::getAbsoluteWebPath(Environment::getPublicPath() . '/index.php'));
                $publicUrl .= '?' . http_build_query($queryParameterArray, '', '&', PHP_QUERY_RFC3986);
                $publicUrlPlain = GeneralUtility::locationHeaderUrl(PathUtility::getAbsoluteWebPath(Environment::getPublicPath())) . $this->downloadFile->getPublicUrl();
                $label = LocalizationUtility::translate("download", "powermailpdf");
                //Adds a field for the download link at the thx site
                /* @var $answer \In2code\Powermail\Domain\Model\Answer */
                $answer = GeneralUtility::makeInstance(Answer::class);
                /* @var $field \In2code\Powermail\Domain\Model\Field */
                $field = GeneralUtility::makeInstance(Field::class);
                $field->setTitle(LocalizationUtility::translate('downloadLink', 'powermailpdf'));
                $field->setMarker('downloadLink');
                $field->setType('downloadLink');
                $answer->setField($field);
//                $answer->setValue('<a href="' . $publicUrl . '" title="' . $label . '" target="_blank" class="ico-class" type="button">' . basename($powermailPdfFile) . '</a>');
                $answer->setValue($this->render($publicUrl, $label, basename($powermailPdfFile)));
                $mail->addAnswer($answer);
            }

            if ($this->settings['email.']['attachFile']) {
                // set pdf filename for attachment via TypoScript
                if ($formController) {
                    /** @var FormController $formController */
                    $settingsPowermail = $formController->getSettings();
                    $settingsPowermail['receiver']['addAttachment']['value'] = $powermailPdfFile;
                    $settingsPowermail['sender']['addAttachment']['value'] = $powermailPdfFile;
                    $formController->setSettings($settingsPowermail);
                } else {
                    $mail->setAdditionalData(['powermailpdf_file' => $powermailPdfFile, 'powermailpdf_filename' => $this->fileName]);
                }
            }
        }
    }

    /**
     * Signal slot sendTemplateEmailBeforeSend
     *
     * @param MailMessage $message
     * @param \array $email
     * @param SendMailService $sendMailService
     */
    public function manipulateMail(MailMessage $message, array &$email, SendMailService $sendMailService)
    {
        if ($this->settings['enablePowermailPdf']) {
            if (($sendMailService->getType() === 'receiver' && $this->settings['receiver.']['attachment'] == 1) || ($sendMailService->getType() === 'sender' && $this->settings['sender.']['attachment'] == 1)) {
                if ($this->settings['email.']['attachFile']) {
                    // set pdf filename for attachment via TypoScript
                    if (!method_exists(FormController::class, 'setSettings')) {
                        $additionalData = $sendMailService->getMail()->getAdditionalData();
                        if (isset($additionalData['powermailpdf_file'])) {
                            $powermailPdfFile = $additionalData['powermailpdf_file'];
                            $attachment = \Swift_Attachment::fromPath($powermailPdfFile)->setFilename($additionalData['powermailpdf_filename']);
                            $message->attach($attachment);
                        }
                    }
                }
            }
            if ($this->settings['showDownloadLink'] && $this->settings['target.']['pdf']) $message->setBody(html_entity_decode($message->getBody()));
        }

        if ($sendMailService->getType() === 'receiver') {
            $message->send();
            if ($email['variables']['hash'] === '') {
                $sendMailService->getMail()->setSenderMail($email['senderEmail']);
                $sendMailService->getMail()->setSenderName($email['senderName']);
                $sendMailService->getMail()->setReceiverMail($email['receiverEmail']);
                $sendMailService->getMail()->setSubject($email['subject']);
            }
            GeneralUtility::unlink_tempfile($powermailPdfFile);
            return $message->isSent();
        }
        return null;
    }
}
