<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Stanislav Turza <sorien@mail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sorien\DataGridBundle\Grid\Column;

use Sorien\DataGridBundle\Grid\Filter;

class MoneyColumn extends TextColumn
{
    public function __initialize(array $params)
    {
        parent::__initialize($params);
    }

    public function renderCell($value, $row, $router)
    {
        if ($value != null)
        {
            // cross-platform money formatter; remember that money_format is not available on some platforms
            setlocale(LC_ALL, $this->locale);
            $locale = localeconv();
            $money = $locale['currency_symbol'] . number_format($value, $locale['frac_digits'], $locale['decimal_point'], $locale['thousands_sep']);
            return parent::renderCell($money, $row, $router);
        }
        else
        {
            return '';
        }
    }

    public function getType()
    {
        return 'money';
    }

    public function getParentType()
    {
        return 'text';
    }
}
