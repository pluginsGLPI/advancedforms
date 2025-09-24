# Documentation for developers

## Adding new question types

Create a new final class in `src/Model/QuestionType/`, which should:  
* Be in the `GlpiPlugin\Advancedforms\Model\QuestionType` namespace.  
* Extends `Glpi\Form\QuestionType\AbstractQuestionType`.  
* Implements `GlpiPlugin\Advancedforms\Model\Config\ConfigurableItemInterface`. 

Then, register your class in `GlpiPlugin\Advancedforms\Service\ConfigManager::getConfigurableQuestionTypes()`.

Thats it, your new question type should appear in the configuration page and will be usable in forms once enabled.

Make sure to add a dedicated test file in `tests/Model/QuestionType`.
This test should extends `GlpiPlugin\Advancedforms\Tests\Model\QuestionType;\QuestionTypeTestCase`.
