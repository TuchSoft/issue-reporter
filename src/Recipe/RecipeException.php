<?php

namespace Tuchsoft\IssueReporter\Recipe;

/**
 * Thrown when a recipe/config file is missing, unreadable, malformed or
 * does not conform to the expected schema.
 */
class RecipeException extends \RuntimeException
{
}
