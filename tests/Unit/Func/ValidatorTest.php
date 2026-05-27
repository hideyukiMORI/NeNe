<?php

declare(strict_types=1);

namespace Tests\Unit\Func;

use Nene\Func\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    // -----------------------------------------------------------------------
    // required()
    // -----------------------------------------------------------------------

    public function testRequiredPassesWhenPresent(): void
    {
        $v = new Validator(['title' => 'Hello']);
        $v->required('title');
        $this->assertTrue($v->passes());
    }

    public function testRequiredFailsWhenAbsent(): void
    {
        $v = new Validator([]);
        $v->required('title');
        $this->assertFalse($v->passes());
        $this->assertArrayHasKey('title', $v->errors());
    }

    public function testRequiredFailsWhenEmptyString(): void
    {
        $v = new Validator(['title' => '']);
        $v->required('title');
        $this->assertFalse($v->passes());
    }

    public function testRequiredFailsWhenNull(): void
    {
        $v = new Validator(['title' => null]);
        $v->required('title');
        $this->assertFalse($v->passes());
    }

    public function testRequiredFailsWhenEmptyArray(): void
    {
        $v = new Validator(['tags' => []]);
        $v->required('tags');
        $this->assertFalse($v->passes());
    }

    public function testRequiredCanCheckMultipleFields(): void
    {
        $v = new Validator(['title' => 'Hello']);
        $v->required('title', 'email');
        $errors = $v->errors();
        $this->assertArrayNotHasKey('title', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    // -----------------------------------------------------------------------
    // maxLength()
    // -----------------------------------------------------------------------

    public function testMaxLengthPassesWhenWithin(): void
    {
        $v = new Validator(['title' => 'Hello']);
        $v->maxLength('title', 10);
        $this->assertTrue($v->passes());
    }

    public function testMaxLengthFailsWhenExceeded(): void
    {
        $v = new Validator(['title' => 'Hello World']);
        $v->maxLength('title', 5);
        $this->assertFalse($v->passes());
        $this->assertStringContainsString('5', $v->errors()['title'][0]);
    }

    public function testMaxLengthSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->maxLength('title', 10);
        $this->assertTrue($v->passes());
    }

    public function testMaxLengthSkipsEmptyString(): void
    {
        $v = new Validator(['title' => '']);
        $v->maxLength('title', 3);
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // minLength()
    // -----------------------------------------------------------------------

    public function testMinLengthPassesWhenMeets(): void
    {
        $v = new Validator(['password' => 'abcdef']);
        $v->minLength('password', 6);
        $this->assertTrue($v->passes());
    }

    public function testMinLengthFailsWhenBelowMin(): void
    {
        $v = new Validator(['password' => 'abc']);
        $v->minLength('password', 6);
        $this->assertFalse($v->passes());
        $this->assertStringContainsString('6', $v->errors()['password'][0]);
    }

    public function testMinLengthSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->minLength('password', 6);
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // email()
    // -----------------------------------------------------------------------

    public function testEmailPassesValidEmail(): void
    {
        $v = new Validator(['email' => 'user@example.com']);
        $v->email('email');
        $this->assertTrue($v->passes());
    }

    public function testEmailFailsInvalidEmail(): void
    {
        $v = new Validator(['email' => 'not-an-email']);
        $v->email('email');
        $this->assertFalse($v->passes());
    }

    public function testEmailSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->email('email');
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // url()
    // -----------------------------------------------------------------------

    public function testUrlPassesValidUrl(): void
    {
        $v = new Validator(['website' => 'https://example.com']);
        $v->url('website');
        $this->assertTrue($v->passes());
    }

    public function testUrlFailsInvalidUrl(): void
    {
        $v = new Validator(['website' => 'not a url']);
        $v->url('website');
        $this->assertFalse($v->passes());
    }

    public function testUrlSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->url('website');
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // integer()
    // -----------------------------------------------------------------------

    public function testIntegerPassesValidInt(): void
    {
        $v = new Validator(['age' => '25']);
        $v->integer('age');
        $this->assertTrue($v->passes());
    }

    public function testIntegerFailsNonNumeric(): void
    {
        $v = new Validator(['age' => 'twenty']);
        $v->integer('age');
        $this->assertFalse($v->passes());
    }

    public function testIntegerFailsBelowMin(): void
    {
        $v = new Validator(['age' => '-1']);
        $v->integer('age', min: 0);
        $this->assertFalse($v->passes());
        $this->assertStringContainsString('0', $v->errors()['age'][0]);
    }

    public function testIntegerFailsAboveMax(): void
    {
        $v = new Validator(['age' => '200']);
        $v->integer('age', max: 150);
        $this->assertFalse($v->passes());
        $this->assertStringContainsString('150', $v->errors()['age'][0]);
    }

    public function testIntegerPassesWithinRange(): void
    {
        $v = new Validator(['age' => '25']);
        $v->integer('age', min: 0, max: 150);
        $this->assertTrue($v->passes());
    }

    public function testIntegerSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->integer('age', min: 0, max: 150);
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // in()
    // -----------------------------------------------------------------------

    public function testInPassesAllowedValue(): void
    {
        $v = new Validator(['status' => 'active']);
        $v->in('status', ['active', 'inactive', 'pending']);
        $this->assertTrue($v->passes());
    }

    public function testInFailsDisallowedValue(): void
    {
        $v = new Validator(['status' => 'deleted']);
        $v->in('status', ['active', 'inactive', 'pending']);
        $this->assertFalse($v->passes());
    }

    public function testInSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->in('status', ['active', 'inactive']);
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // regex()
    // -----------------------------------------------------------------------

    public function testRegexPassesMatch(): void
    {
        $v = new Validator(['code' => 'ABC123']);
        $v->regex('code', '/^[A-Z]{3}\d{3}$/');
        $this->assertTrue($v->passes());
    }

    public function testRegexFailsNoMatch(): void
    {
        $v = new Validator(['code' => 'abc123']);
        $v->regex('code', '/^[A-Z]{3}\d{3}$/');
        $this->assertFalse($v->passes());
    }

    public function testRegexUsesCustomMessage(): void
    {
        $v = new Validator(['code' => 'abc123']);
        $v->regex('code', '/^[A-Z]{3}\d{3}$/', 'Code must be 3 uppercase letters followed by 3 digits.');
        $this->assertSame('Code must be 3 uppercase letters followed by 3 digits.', $v->errors()['code'][0]);
    }

    public function testRegexUsesDefaultMessageWhenNoneProvided(): void
    {
        $v = new Validator(['code' => 'abc123']);
        $v->regex('code', '/^[A-Z]{3}\d{3}$/');
        $this->assertStringContainsString('invalid format', $v->errors()['code'][0]);
    }

    public function testRegexSkipsAbsentField(): void
    {
        $v = new Validator([]);
        $v->regex('code', '/^[A-Z]+$/');
        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // passes() / errors() / firstErrors()
    // -----------------------------------------------------------------------

    public function testPassesReturnsTrueWhenNoErrors(): void
    {
        $v = new Validator(['title' => 'Hello', 'email' => 'user@example.com']);
        $v->required('title', 'email')->email('email');
        $this->assertTrue($v->passes());
    }

    public function testPassesReturnsFalseWhenErrors(): void
    {
        $v = new Validator([]);
        $v->required('title');
        $this->assertFalse($v->passes());
    }

    public function testErrorsReturnAllMessages(): void
    {
        $v = new Validator(['email' => 'bad', 'title' => '']);
        $v->required('title')->email('email');
        $errors = $v->errors();
        $this->assertArrayHasKey('title', $errors);
        $this->assertArrayHasKey('email', $errors);
        $this->assertIsArray($errors['title']);
        $this->assertIsArray($errors['email']);
    }

    public function testFirstErrorsReturnOnePerField(): void
    {
        $v = new Validator(['title' => '']);
        $v->required('title')->minLength('title', 5);
        // required() adds one error; minLength skips because value is ''
        $first = $v->firstErrors();
        $this->assertArrayHasKey('title', $first);
        $this->assertIsString($first['title']);
    }

    // -----------------------------------------------------------------------
    // Combination tests
    // -----------------------------------------------------------------------

    public function testMultipleRulesChain(): void
    {
        $v = new Validator([
            'title' => 'My Article',
            'email' => 'author@example.com',
        ]);
        $v->required('title', 'email')
          ->maxLength('title', 200)
          ->email('email');
        $this->assertTrue($v->passes());
        $this->assertSame([], $v->errors());
    }

    public function testMultipleErrorsAccumulate(): void
    {
        // A short password that also fails minLength
        $v = new Validator(['password' => 'ab']);
        $v->minLength('password', 8)->maxLength('password', 6);
        // minLength fails (2 < 8), maxLength passes (2 <= 6)
        $errors = $v->errors();
        $this->assertCount(1, $errors['password']);
        $this->assertStringContainsString('8', $errors['password'][0]);
    }

    public function testMultipleErrorsOnSameFieldWithTwoViolations(): void
    {
        // Field value is both missing (required) — demonstrated via required + integer
        // Use a field present but invalid: not an integer and below min would give 1 error
        // Instead: use required twice or minLength twice with different mins
        // Let's do: required fires once; then minLength also fires (empty string)
        // Actually minLength/maxLength skip empty strings.
        // Use integer: 'abc' fails integer check (1 error) then min check is skipped.
        // Better: use in() + email() both on same non-empty bad value
        $v = new Validator(['contact' => 'not-an-email-or-url']);
        $v->email('contact')->url('contact');
        $errors = $v->errors();
        $this->assertCount(2, $errors['contact']);
    }

    public function testChainReturnsStaticForFluency(): void
    {
        $v = new Validator(['name' => 'Alice']);
        $result = $v->required('name')
                    ->maxLength('name', 50)
                    ->minLength('name', 2);
        $this->assertSame($v, $result);
    }

    public function testIntegerPassesIntegerType(): void
    {
        // PHP integer value (not string) should also pass
        $v = new Validator(['count' => 5]);
        $v->integer('count', min: 1, max: 10);
        $this->assertTrue($v->passes());
    }
}
