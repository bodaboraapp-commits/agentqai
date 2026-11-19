import * as React from 'react';
import { usePage } from '@inertiajs/react';
import { CheckIcon, ChevronsUpDown } from 'lucide-react';
import * as RPNInput from 'react-phone-number-input';
import flags from 'react-phone-number-input/flags';

import { Button } from '@/components/ui/button';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';

import { cn } from '@/lib/utils';
import { normalizeToE164 } from '@/lib/phone-validation';

type PhoneInputValue = RPNInput.Value | undefined;

export interface PhoneInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'value'> {
  value?: PhoneInputValue;
  onChange?: (value: PhoneInputValue) => void;
  defaultCountry?: RPNInput.Country;
  label?: string;
  error?: string;
}

/** Map an E.164 calling code (+254) to its country (KE) for the flag select. */
function countryFromDialCode(dialCode?: string): RPNInput.Country | undefined {
  const digits = (dialCode ?? '').replace(/\D/g, '');
  if (digits === '') return undefined;

  for (const country of RPNInput.getCountries()) {
    if (RPNInput.getCountryCallingCode(country) === digits) {
      return country;
    }
  }

  return undefined;
}

const PhoneInput: React.ForwardRefExoticComponent<PhoneInputProps> = React.forwardRef<
  React.ElementRef<typeof RPNInput.default>,
  PhoneInputProps
>(({ className, onChange, defaultCountry, label, error, value, ...props }, ref) => {
  // Auto-detect the country: explicit prop first, then the installation's
  // telephony.default_country_code (+254 for an AT install), then US.
  const page = usePage();
  const sharedDialCode = (page.props as { telephony?: { default_country_code?: string } })?.telephony?.default_country_code;
  const resolvedDefault = defaultCountry ?? countryFromDialCode(sharedDialCode) ?? 'US';

  const [country, setCountry] = React.useState<RPNInput.Country>(resolvedDefault);

  // Force the input value to E.164 with the resolved country as the assumed
  // country, so ambiguous digits ("2083421813") are not read as +20 Egypt.
  const normalizedValue = React.useMemo<PhoneInputValue>(() => {
    if (value === undefined || value === null || value === '') return undefined;
    const str = String(value);
    // If already E.164, pass through; otherwise normalize using the default.
    if (str.startsWith('+')) return str as RPNInput.Value;
    const normalized = normalizeToE164(str, resolvedDefault);
    return (normalized || undefined) as PhoneInputValue;
  }, [value, resolvedDefault]);

  // Keep the flag in sync with whatever number is typed/pasted/selected:
  // "+254…" switches the flag to Kenya even if the default was US.
  const detectedCountry = React.useMemo<RPNInput.Country | undefined>(() => {
    if (normalizedValue === undefined) return undefined;
    const parsed = RPNInput.parsePhoneNumber(String(normalizedValue));
    return parsed?.country as RPNInput.Country | undefined;
  }, [normalizedValue]);

  React.useEffect(() => {
    setCountry(detectedCountry ?? resolvedDefault);
  }, [detectedCountry, resolvedDefault]);

  return (
    <div className="space-y-2">
      {label && <Label>{label}</Label>}
      <RPNInput.default
        ref={ref}
        className={cn('flex', className)}
        flagComponent={FlagComponent}
        countrySelectComponent={CountrySelect}
        inputComponent={InputComponent}
        defaultCountry={resolvedDefault}
        country={country}
        onCountryChange={(nextCountry) => {
          if (nextCountry) setCountry(nextCountry);
        }}
        value={normalizedValue}
        onChange={(v) => onChange?.(v)}
        {...props}
      />
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
});
PhoneInput.displayName = 'PhoneInput';

const InputComponent = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
  ({ className, ...props }, ref) => (
    <Input
      className={cn('rounded-e-lg rounded-s-none', className)}
      {...props}
      ref={ref}
    />
  )
);
InputComponent.displayName = 'InputComponent';

type CountrySelectOption = { label: string; value: RPNInput.Country };

type CountrySelectProps = {
  disabled?: boolean;
  value: RPNInput.Country;
  onChange: (value: RPNInput.Country) => void;
  options: CountrySelectOption[];
};

const CountrySelect = ({ disabled, value, onChange, options }: CountrySelectProps) => {
  const handleSelect = React.useCallback(
    (country: RPNInput.Country) => {
      onChange(country);
    },
    [onChange]
  );

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant={'outline'}
          className={cn('flex gap-1 rounded-e-none rounded-s-lg px-3')}
          disabled={disabled}
        >
          <FlagComponent country={value} countryName={value} />
          <ChevronsUpDown
            className={cn(
              '-mr-2 h-4 w-4 opacity-50',
              disabled ? 'hidden' : 'opacity-100'
            )}
          />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-[300px] p-0 z-[10000]">
        <Command>
          <CommandList>
            <CommandInput placeholder="Search country..." />
            <CommandEmpty>No country found.</CommandEmpty>
            <CommandGroup>
              {options && options.length > 0 && options
                .filter((x) => x.value)
                .map((option) => (
                  <CommandItem
                    className="gap-2"
                    key={option.value}
                    onSelect={() => handleSelect(option.value)}
                  >
                    <FlagComponent
                      country={option.value}
                      countryName={option.label}
                    />
                    <span className="flex-1 text-sm">{option.label}</span>
                    {option.value && (
                      <span className="text-foreground/50 text-sm">
                        {`+${RPNInput.getCountryCallingCode(option.value)}`}
                      </span>
                    )}
                    <CheckIcon
                      className={cn(
                        'ml-auto h-4 w-4',
                        option.value === value ? 'opacity-100' : 'opacity-0'
                      )}
                    />
                  </CommandItem>
                ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
};

const FlagComponent = ({ country, countryName }: RPNInput.FlagProps) => {
  const Flag = flags[country];

  return (
    <span className="bg-foreground/20 flex h-4 w-6 overflow-hidden rounded-sm">
      {Flag && <Flag title={countryName} />}
    </span>
  );
};
FlagComponent.displayName = 'FlagComponent';

export { PhoneInput }
