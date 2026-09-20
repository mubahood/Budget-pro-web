<?php

namespace App\Exceptions;

/**
 * Thrown by model boot() hooks (BudgetProgram, BudgetItemCategory, BudgetItem,
 * ContributionRecord) for business-rule rejections (duplicate name, treasurer
 * from another company, etc.) -- deliberately a distinct type from a plain
 * \Exception so BaseCrudController can render these as a clean 422 without
 * also swallowing genuine infrastructure failures (DB errors, etc.) as if
 * they were validation problems.
 */
class BusinessRuleException extends \Exception
{
}
