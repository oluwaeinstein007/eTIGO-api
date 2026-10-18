<?php

namespace App\Enums;

enum LostItemCategory: string
{
    case Phone = 'phone';
    case WalletOrPurse = 'wallet_or_purse';
    case Bag = 'bag';
    case Keys = 'keys';
    case IdOrDocuments = 'id_or_documents';
    case Electronics = 'electronics';
    case Clothing = 'clothing';
    case Glasses = 'glasses';
    case Jewellery = 'jewellery';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::WalletOrPurse => 'Wallet or purse',
            self::Bag => 'Bag or luggage',
            self::Keys => 'Keys',
            self::IdOrDocuments => 'ID card or documents',
            self::Electronics => 'Laptop, tablet or other electronics',
            self::Clothing => 'Clothing',
            self::Glasses => 'Glasses',
            self::Jewellery => 'Jewellery or watch',
            self::Other => 'Something else',
        };
    }

    /**
     * Lower-case label for sentences ("your phone", "your keys").
     */
    public function noun(): string
    {
        return match ($this) {
            self::Phone => 'phone',
            self::WalletOrPurse => 'wallet or purse',
            self::Bag => 'bag',
            self::Keys => 'keys',
            self::IdOrDocuments => 'ID or documents',
            self::Electronics => 'electronics',
            self::Clothing => 'clothing',
            self::Glasses => 'glasses',
            self::Jewellery => 'jewellery',
            self::Other => 'item',
        };
    }
}
