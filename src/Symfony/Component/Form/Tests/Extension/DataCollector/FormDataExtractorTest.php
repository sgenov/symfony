<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Form\Tests\Extension\DataCollector;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\DataMapper\DataMapper;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\DataCollector\FormDataExtractor;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactory;
use Symfony\Component\Form\FormRegistry;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\ResolvedFormType;
use Symfony\Component\Form\ResolvedFormTypeFactory;
use Symfony\Component\Form\Tests\Fixtures\FixedDataTransformer;
use Symfony\Component\Validator\Constraints\WordCount;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\VarDumper\Test\VarDumperTestTrait;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class FormDataExtractorTest extends TestCase
{
    use VarDumperTestTrait;

    private FormDataExtractor $dataExtractor;

    protected function setUp(): void
    {
        $this->dataExtractor = new FormDataExtractor();
    }

    public function testExtractConfiguration()
    {
        $form = $this->createBuilder('name')
            ->setType(new ResolvedFormType(new HiddenType()))
            ->getForm();

        $this->assertSame([
            'id' => 'name',
            'name' => 'name',
            'type_class' => HiddenType::class,
            'synchronized' => true,
            'passed_options' => [],
            'resolved_options' => [],
        ], $this->dataExtractor->extractConfiguration($form));
    }

    public function testExtractConfigurationSortsPassedOptions()
    {
        $options = [
            'b' => 'foo',
            'a' => 'bar',
            'c' => 'baz',
        ];

        $form = $this->createBuilder('name')
            ->setType(new ResolvedFormType(new HiddenType()))
            // passed options are stored in an attribute by
            // ResolvedTypeDataCollectorProxy
            ->setAttribute('data_collector/passed_options', $options)
            ->getForm();

        $this->assertSame([
            'id' => 'name',
            'name' => 'name',
            'type_class' => HiddenType::class,
            'synchronized' => true,
            'passed_options' => [
                'a' => 'bar',
                'b' => 'foo',
                'c' => 'baz',
            ],
            'resolved_options' => [],
        ], $this->dataExtractor->extractConfiguration($form));
    }

    public function testExtractConfigurationSortsResolvedOptions()
    {
        $options = [
            'b' => 'foo',
            'a' => 'bar',
            'c' => 'baz',
        ];

        $form = $this->createBuilder('name', $options)
            ->setType(new ResolvedFormType(new HiddenType()))
            ->getForm();

        $this->assertSame([
            'id' => 'name',
            'name' => 'name',
            'type_class' => HiddenType::class,
            'synchronized' => true,
            'passed_options' => [],
            'resolved_options' => [
                'a' => 'bar',
                'b' => 'foo',
                'c' => 'baz',
            ],
        ], $this->dataExtractor->extractConfiguration($form));
    }

    public function testExtractConfigurationBuildsIdRecursively()
    {
        $grandParent = $this->createBuilder('grandParent')
            ->setCompound(true)
            ->setDataMapper(new DataMapper())
            ->getForm();
        $parent = $this->createBuilder('parent')
            ->setCompound(true)
            ->setDataMapper(new DataMapper())
            ->getForm();
        $form = $this->createBuilder('name')
            ->setType(new ResolvedFormType(new HiddenType()))
            ->getForm();

        $grandParent->add($parent);
        $parent->add($form);

        $this->assertSame([
            'id' => 'grandParent_parent_name',
            'name' => 'name',
            'type_class' => HiddenType::class,
            'synchronized' => true,
            'passed_options' => [],
            'resolved_options' => [],
        ], $this->dataExtractor->extractConfiguration($form));
    }

    public function testExtractDefaultData()
    {
        $form = $this->createBuilder('name')->getForm();

        $form->setData('Foobar');

        $this->assertSame([
            'default_data' => [
                'norm' => 'Foobar',
            ],
            'submitted_data' => [],
        ], $this->dataExtractor->extractDefaultData($form));
    }

    public function testExtractDefaultDataStoresModelDataIfDifferent()
    {
        $form = $this->createBuilder('name')
            ->addModelTransformer(new FixedDataTransformer([
                'Foo' => 'Bar',
            ]))
            ->getForm();

        $form->setData('Foo');

        $this->assertSame([
            'default_data' => [
                'norm' => 'Bar',
                'model' => 'Foo',
            ],
            'submitted_data' => [],
        ], $this->dataExtractor->extractDefaultData($form));
    }

    public function testExtractDefaultDataStoresViewDataIfDifferent()
    {
        $form = $this->createBuilder('name')
            ->addViewTransformer(new FixedDataTransformer([
                'Foo' => 'Bar',
            ]))
            ->getForm();

        $form->setData('Foo');

        $this->assertSame([
            'default_data' => [
                'norm' => 'Foo',
                'view' => 'Bar',
            ],
            'submitted_data' => [],
        ], $this->dataExtractor->extractDefaultData($form));
    }

    public function testExtractSubmittedData()
    {
        $form = $this->createBuilder('name')->getForm();

        $form->submit('Foobar');

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Foobar',
            ],
            'errors' => [],
            'synchronized' => true,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractSubmittedDataStoresModelDataIfDifferent()
    {
        $form = $this->createBuilder('name')
            ->addModelTransformer(new FixedDataTransformer([
                'Foo' => 'Bar',
                '' => '',
            ]))
            ->getForm();

        $form->submit('Bar');

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Bar',
                'model' => 'Foo',
            ],
            'errors' => [],
            'synchronized' => true,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractSubmittedDataStoresViewDataIfDifferent()
    {
        $form = $this->createBuilder('name')
            ->addViewTransformer(new FixedDataTransformer([
                'Foo' => 'Bar',
                '' => '',
            ]))
            ->getForm();

        $form->submit('Bar');

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Foo',
                'view' => 'Bar',
            ],
            'errors' => [],
            'synchronized' => true,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractSubmittedDataStoresErrors()
    {
        $form = $this->createBuilder('name')->getForm();

        $form->submit('Foobar');
        $form->addError(new FormError('Invalid!'));

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Foobar',
            ],
            'errors' => [
                ['message' => 'Invalid!', 'origin' => spl_object_hash($form), 'trace' => []],
            ],
            'synchronized' => true,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractSubmittedDataStoresErrorOrigin()
    {
        $form = $this->createBuilder('name')->getForm();

        $error = new FormError('Invalid!');
        $error->setOrigin($form);

        $form->submit('Foobar');
        $form->addError($error);

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Foobar',
            ],
            'errors' => [
                ['message' => 'Invalid!', 'origin' => spl_object_hash($form), 'trace' => []],
            ],
            'synchronized' => true,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractSubmittedDataStoresErrorCause()
    {
        $form = $this->createBuilder('name')->getForm();

        $exception = new \Exception();
        $violation = new ConstraintViolation('Foo', 'Foo', [], 'Root', 'property.path', 'Invalid!', null, null, null, $exception);

        $form->submit('Foobar');
        $form->addError(new FormError('Invalid!', null, [], null, $violation));
        $origin = spl_object_hash($form);

        if (class_exists(WordCount::class)) {
            $expectedFormat = <<<"EODUMP"
                array:3 [
                  "submitted_data" => array:1 [
                    "norm" => "Foobar"
                  ]
                  "errors" => array:1 [
                    0 => array:3 [
                      "message" => "Invalid!"
                      "origin" => "$origin"
                      "trace" => array:2 [
                        0 => Symfony\Component\Validator\ConstraintViolation {
                          -message: "Foo"
                          -messageTemplate: "Foo"
                          -parameters: []
                          -root: "Root"
                          -propertyPath: "property.path"
                          -invalidValue: "Invalid!"
                          -plural: null
                          -code: null
                          -constraint: null
                          -cause: Exception {%A}
                        }
                        1 => Exception {#1}
                      ]
                    ]
                  ]
                  "synchronized" => true
                ]
                EODUMP;
        } else {
            $expectedFormat = <<<"EODUMP"
                array:3 [
                  "submitted_data" => array:1 [
                    "norm" => "Foobar"
                  ]
                  "errors" => array:1 [
                    0 => array:3 [
                      "message" => "Invalid!"
                      "origin" => "$origin"
                      "trace" => array:2 [
                        0 => Symfony\Component\Validator\ConstraintViolation {
                          -message: "Foo"
                          -messageTemplate: "Foo"
                          -parameters: []
                          -plural: null
                          -root: "Root"
                          -propertyPath: "property.path"
                          -invalidValue: "Invalid!"
                          -constraint: null
                          -code: null
                          -cause: Exception {%A}
                        }
                        1 => Exception {#1}
                      ]
                    ]
                  ]
                  "synchronized" => true
                ]
                EODUMP;
        }
        $this->assertDumpMatchesFormat($expectedFormat
            ,
            $this->dataExtractor->extractSubmittedData($form)
        );
    }

    public function testExtractSubmittedDataRemembersIfNonSynchronized()
    {
        $form = $this->createBuilder('name')
            ->addModelTransformer(new CallbackTransformer(
                function () {},
                function () {
                    throw new TransformationFailedException('Fail!');
                }
            ))
            ->getForm();

        $form->submit('Foobar');

        $this->assertSame([
            'submitted_data' => [
                'norm' => 'Foobar',
                'model' => null,
            ],
            'errors' => [],
            'synchronized' => false,
        ], $this->dataExtractor->extractSubmittedData($form));
    }

    public function testExtractViewVariables()
    {
        $view = new FormView();

        $view->vars = [
            'b' => 'foo',
            'a' => 'bar',
            'c' => 'baz',
            'id' => 'foo_bar',
            'name' => 'bar',
        ];

        $this->assertSame([
            'id' => 'foo_bar',
            'name' => 'bar',
            'view_vars' => [
                'a' => 'bar',
                'b' => 'foo',
                'c' => 'baz',
                'id' => 'foo_bar',
                'name' => 'bar',
            ],
        ], $this->dataExtractor->extractViewVariables($view));
    }

    private function createBuilder(string $name, array $options = []): FormBuilder
    {
        return new FormBuilder($name, null, new EventDispatcher(), new FormFactory(new FormRegistry([], new ResolvedFormTypeFactory())), $options);
    }
}
