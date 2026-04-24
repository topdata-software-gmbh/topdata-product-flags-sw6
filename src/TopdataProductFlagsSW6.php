<?php declare(strict_types=1);

namespace Topdata\TopdataProductFlagsSW6;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Topdata\TopdataProductFlagsSW6\Service\CustomFieldInstaller;

class TopdataProductFlagsSW6 extends Plugin
{
	public function install(InstallContext $installContext): void
	{
		parent::install($installContext);

		$this->getCustomFieldInstaller()->install($installContext->getContext());
	}

	public function uninstall(UninstallContext $uninstallContext): void
	{
		parent::uninstall($uninstallContext);

		if ($uninstallContext->keepUserData()) {
			return;
		}

		$this->getCustomFieldInstaller()->uninstall($uninstallContext->getContext());
	}

	private function getCustomFieldInstaller(): CustomFieldInstaller
	{
		return new CustomFieldInstaller(
			$this->container->get('custom_field_set.repository')
		);
	}
}