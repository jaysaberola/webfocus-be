<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Option;

class OptionSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['animation','Fade In','fadeIn','entrance'],
            ['animation','Fade Out','fadeOut','exit'],
            ['animation','Fade In Down','fadeInDown','entrance'],
            ['animation','Fade Out Down','fadeOutDown','exit'],
            ['animation','Fade In Down Big','fadeInDownBig','entrance'],
            ['animation','Fade Out Down Big','fadeOutDownBig','exit'],
            ['animation','Fade In Left','fadeInLeft','entrance'],
            ['animation','Fade Out Left','fadeOutLeft','exit'],
            ['animation','Fade In Left Big','fadeInLeftBig','entrance'],
            ['animation','Fade Out Left Big','fadeOutDownBig','exit'],
            ['animation','Fade In Right','fadeInRight','entrance'],
            ['animation','Fade Out Right','fadeOutRight','exit'],
            ['animation','Fade In Right Big','fadeInRightBig','entrance'],
            ['animation','Fade Out Right Big','fadeInRightBig','exit'],
            ['animation','Fade In Up','fadeInUp','entrance'],
            ['animation','Fade Out Up','fadeOutUp','exit'],
            ['animation','Fade In Up Big','fadeInUpBig','entrance'],
            ['animation','Fade Out Up Big','fadeInUpBig','exit'],
            ['animation','Bounce In','bounceIn','entrance'],
            ['animation','Bounce Out','bounceOut','exit'],
            ['animation','Bounce In Down','bounceInDown','entrance'],
            ['animation','Bounce Out Down','bounceOutDown','exit'],
            ['animation','Bounce In Left','bounceInLeft','entrance'],
            ['animation','Bounce Out Left','bounceOutLeft','exit'],
            ['animation','Bounce In Right','bounceInRight','entrance'],
            ['animation','Bounce Out Right','bounceOutRight','exit'],
            ['animation','Bounce In Up','bounceInUp','entrance'],
            ['animation','Bounce Out Up','bounceOutUp','exit'],
            ['animation','Rotate In','rotateIn','entrance'],
            ['animation','Rotate Out','rotateOut','exit'],
            ['animation','Rotate In Down Left','rotateInDownLeft','entrance'],
            ['animation','Rotate Out Down Left','rotateOutDownLeft','exit'],
            ['animation','Rotate In Down Right','rotateInDownRight','entrance'],
            ['animation','Rotate Out Down Right','rotateOutDownRight','exit'],
            ['animation','Rotate In Up Left','rotateInUpLeft','entrance'],
            ['animation','Rotate Out Up Left','rotateOutUpLeft','exit'],
            ['animation','Rotate In Up Right','rotateInUpRight','entrance'],
            ['animation','Rotate Out Up Right','rotateOutUpRight','exit'],
            ['animation','Slide In Up','slideInUp','entrance'],
            ['animation','Slide Out Up','slideOutUp','exit'],
            ['animation','Slide In Down','slideInDown','entrance'],
            ['animation','Slide Out Down','slideOutDown','exit'],
            ['animation','Slide In Left','slideInLeft','entrance'],
            ['animation','Slide Out Left','slideOutLeft','exit'],
            ['animation','Slide In Right','slideInRight','entrance'],
            ['animation','Slide Out Right','slideOutRight','exit'],
            ['animation','Zoom In','zoomIn','entrance'],
            ['animation','Zoom Out','zoomOut','exit'],
            ['animation','Zoom In Down','zoomInDown','entrance'],
            ['animation','Zoom Out Down','zoomOutDown','exit'],
            ['animation','Zoom In Left','zoomInLeft','entrance'],
            ['animation','Zoom Out Left','zoomOutLeft','exit'],
            ['animation','Zoom In Right','zoomInRight','entrance'],
            ['animation','Zoom Out Right','zoomOutRight','exit'],
            ['animation','Zoom In Up','zoomInUp','entrance'],
            ['animation','Zoom Out Up','zoomOutUp','exit'],
        ];

        foreach ($data as [$type, $name, $value, $fieldType]) {
            Option::create([
                'type' => $type,
                'name' => $name,
                'value' => $value,
                'field_type' => $fieldType,
            ]);
        }
    }
}
