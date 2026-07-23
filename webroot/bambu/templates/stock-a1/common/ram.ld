ENTRY(Reset_Handler)
MEMORY
{
  RAM (rwx) : ORIGIN = 0x20000000, LENGTH = 16K
}
SECTIONS
{
  . = ORIGIN(RAM);
  .isr_vector : { KEEP(*(.isr_vector)) } > RAM
  .text : { *(.text*) *(.rodata*) } > RAM
  .data : { *(.data*) } > RAM
  .bss (NOLOAD) : { *(.bss*) *(COMMON) } > RAM
  /DISCARD/ : { *(.comment*) *(.ARM.attributes*) }
}
ASSERT(SIZEOF(.isr_vector) + SIZEOF(.text) + SIZEOF(.data) < 0x3000, "Probe exceeds safe first-SRAM window")
