<?php
if (!defined('TYPO3_MODE')) {
	die ('Access denied.');
}

$signalDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);

$signalDispatcher->connect(
		'In2code\\Powermail\\Controller\\FormController',
		'createActionBeforeRenderView',
		\Undkonsorten\Powermailpdf\Pdf::class,
		'createActionBeforeRenderView'
);

// Change mail
$signalDispatcher->connect(
    \In2code\Powermail\Domain\Service\Mail\SendMailService::class,
    'sendTemplateEmailBeforeSend',
    \Undkonsorten\Powermailpdf\Pdf::class,
    'manipulateMail',
    false
);

$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\In2code\Powermail\ViewHelpers\Misc\VariablesViewHelper::class] = [
    'className' => \Undkonsorten\Powermailpdf\ViewHelpers\Misc\VariablesViewHelper::class
];
