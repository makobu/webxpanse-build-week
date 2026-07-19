ALTER TABLE guided_demo_package_intents
    MODIFY COLUMN package_code ENUM('compass-free', 'solo-launch', 'founder-plus', 'growth-studio', 'scale-custom') NOT NULL;
