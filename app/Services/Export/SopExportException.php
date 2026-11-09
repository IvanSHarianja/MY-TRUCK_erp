<?php

namespace App\Services\Export;

/**
 * Kegagalan export yang pesannya aman & berguna untuk ditampilkan ke user
 * (mis. data melebihi kapasitas baris template SOP).
 */
class SopExportException extends \RuntimeException
{
}
