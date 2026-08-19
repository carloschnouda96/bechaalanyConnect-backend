<?php

namespace App\Concerns;

/**
 * Lets a CMS-generated model hide extra attributes without declaring `$hidden`.
 *
 * WHY THIS EXISTS
 * ---------------
 * Hellotreedigital\Cms\Controllers\CmsPagesController::createModel() rewrites the
 * whole model file from src/stubs/model.stub on every CMS page-schema save, keeping
 * only the text between the `/* Start custom functions *​/` markers. Anything above
 * them is regenerated from the stub.
 *
 * For a *translatable* model the regenerated region hardcodes
 *
 *     protected $hidden = ['translations'];
 *
 * (CmsPagesController.php:607-609). So there are two ways to lose a hidden field:
 *
 *   1. Declare `$hidden` above the markers → it is overwritten with `['translations']`
 *      and every column it was protecting becomes public. This is how
 *      products_variations.cost_price, products.profit_percentage and the supplier
 *      linkage columns were one CMS save away from appearing in public API responses.
 *   2. Declare `$hidden` *inside* the markers → PHP fatal error, because the class
 *      would then declare the same property twice.
 *
 * This trait is the third way. Declare it and `$extraHidden` inside the markers:
 *
 *     /* Start custom functions *​/
 *         use \App\Concerns\HidesExtraAttributes;
 *
 *         protected $extraHidden = ['cost_price'];
 *     /* End custom functions *​/
 *
 * The names are merged into whatever `$hidden` the stub regenerated, so the result
 * is correct both before and after a CMS save.
 *
 * Eloquent calls initialize{TraitName}() from Model::__construct via
 * initializeTraits(), so this runs for every instance with no boot-order concerns.
 */
trait HidesExtraAttributes
{
    public function initializeHidesExtraAttributes(): void
    {
        if (empty($this->extraHidden)) {
            return;
        }

        $this->hidden = array_values(array_unique(
            array_merge($this->hidden, $this->extraHidden)
        ));
    }
}
